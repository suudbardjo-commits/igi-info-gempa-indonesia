<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListDisastersRequest;
use App\Models\DisasterEvent;
use App\Services\GeoSpatial\GeoJsonFormatter;
use App\Services\GeoSpatial\ImpactAssessmentService;
use Illuminate\Http\JsonResponse;

class DisasterController extends Controller
{
    public function __construct(private GeoJsonFormatter $geo) {}

    /**
     * Kejadian terbaru sebagai GeoJSON. Laporan duplikat dari sumber lain
     * (canonical_event_id terisi) tidak ditampilkan.
     */
    public function index(ListDisastersRequest $request): JsonResponse
    {
        $data = $request->validated();

        $events = DisasterEvent::query()
            ->publicFeed(
                hours: (int) ($data['hours'] ?? 168),
                minMagnitude: isset($data['min_magnitude']) ? (float) $data['min_magnitude'] : null,
                source: $data['source'] ?? null,
                limit: (int) ($data['limit'] ?? 200),
            )
            ->get();

        return response()->json($this->geo->disasters($events));
    }

    /** Satu kejadian beserta posko yang masuk radius dampaknya. */
    public function show(string $id, ImpactAssessmentService $impact): JsonResponse
    {
        $event = DisasterEvent::query()->withCoordinates()->findOrFail($id);

        return response()->json([
            ...$this->geo->disasterFeature($event),
            'affected_camps' => $impact->affectedCamps($event)->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'distance_km' => (float) $c->distance_km,
                'capacity_people' => $c->capacity_people,
                'current_occupants' => $c->current_occupants,
            ])->values(),
        ]);
    }
}
