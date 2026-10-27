<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\DisasterEvent;
use App\Services\GeoSpatial\GeoJsonFormatter;
use App\Services\ExternalNews\OfficialNewsFeed;
use App\Services\GeoSpatial\ImpactAssessmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Berita terkini": buletin kejadian yang dibentuk dari data BMKG/USGS yang masuk, ditambah pengumuman operator.
 * Tidak ada artikel buatan tangan; semua isinya berasal dari data yang tercatat.
 */
class NewsController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request, GeoJsonFormatter $geo, OfficialNewsFeed $official): Response
    {
        // Halaman publik yang tautannya dibagikan orang: filter yang tidak dikenal diabaikan (kembali ke bawaan),
        // bukan dijawab dengan pengalihan atau galat.
        $filters = Validator::make($request->only(['hours', 'min_magnitude', 'source']), [
            'hours' => ['nullable', 'integer', 'in:24,168,720'],
            'min_magnitude' => ['nullable', 'numeric', 'in:0,4.5,5,6'],
            'source' => ['nullable', 'in:bmkg,usgs'],
        ])->valid();

        $hours = (int) ($filters['hours'] ?? 168);
        $min = (float) ($filters['min_magnitude'] ?? 0);

        $page = DisasterEvent::query()->withCoordinates()
            ->whereNull('canonical_event_id')
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->when($min > 0, fn ($q) => $q->where('magnitude', '>=', $min))
            ->when(isset($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->orderByDesc('occurred_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('Berita/Index', [
            'events' => $geo->disasters($page->getCollection())['features'],
            'pagination' => [
                'current' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
            ],
            'filters' => ['hours' => $hours, 'min_magnitude' => $min, 'source' => $filters['source'] ?? null],
            'announcements' => Announcement::query()->active()->limit(5)->get()->map->toPublicArray()->values(),
            // Closure: dihitung pada kunjungan penuh, dilewati saat filter gempa diganti (reload parsial).
            'official' => fn () => $official->forPage(24),
        ]);
    }

    public function show(string $id, GeoJsonFormatter $geo, ImpactAssessmentService $impact): Response
    {
        abort_unless(Str::isUuid($id), 404);

        $event = DisasterEvent::query()->withCoordinates()->whereNull('canonical_event_id')->findOrFail($id);

        return Inertia::render('Berita/Show', [
            'event' => $geo->disasterFeature($event),
            'affected_camps' => $impact->affectedCamps($event)->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'distance_km' => (float) $c->distance_km,
                'capacity_people' => $c->capacity_people,
                'current_occupants' => $c->current_occupants,
            ])->values(),
            // Kejadian lain dalam radius 150 km dan ±3 hari, untuk menelusuri gempa susulan.
            'nearby' => $geo->disasters(
                DisasterEvent::query()->withCoordinates()->whereNull('canonical_event_id')
                    ->where('id', '!=', $event->id)
                    ->whereBetween('occurred_at', [$event->occurred_at->copy()->subDays(3), $event->occurred_at->copy()->addDays(3)])
                    ->whereRaw('ST_DWithin(coordinates::geography, ?::geography, 150000)', [sprintf('SRID=4326;POINT(%F %F)', $event->longitude, $event->latitude)])
                    ->orderByDesc('occurred_at')->limit(8)->get(),
            )['features'],
        ]);
    }
}
