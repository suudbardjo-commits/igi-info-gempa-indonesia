<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCamp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeController extends Controller
{
    /**
     * Posko yang diampu pengguna beserta peringatan gempa 72 jam terakhir untuk posko-posko itu.
     * Dipakai halaman Akun sebagai data awal sebelum peringatan langsung datang lewat WebSocket.
     */
    public function overview(Request $request): JsonResponse
    {
        $camps = EvacuationCamp::query()
            ->reportableBy($request->user())
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status', 'capacity_people', 'current_occupants', 'coordinator_id']);

        $alerts = $camps->isEmpty() ? collect() : DB::table('disaster_event_camp as p')
            ->join('disaster_events as d', 'd.id', '=', 'p.disaster_event_id')
            ->join('evacuation_camps as c', 'c.id', '=', 'p.camp_id')
            ->whereIn('p.camp_id', $camps->pluck('id'))
            ->whereNull('d.canonical_event_id')
            ->where('d.occurred_at', '>=', now()->subHours(72))
            ->orderByDesc('d.occurred_at')
            ->limit(20)
            ->get(['d.id as disaster_id', 'd.magnitude', 'd.location_name', 'd.depth_km', 'd.tsunami_potential',
                'd.impact_radius_km', 'd.occurred_at', 'p.distance_km', 'c.id as camp_id', 'c.name as camp_name', 'c.code as camp_code']);

        return response()->json([
            'camps' => $camps->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'status' => $c->status->value,
                'capacity_people' => $c->capacity_people, 'current_occupants' => $c->current_occupants,
                'role' => (int) $c->coordinator_id === $request->user()->id ? 'koordinator' : 'relawan',
            ])->values(),
            'alerts' => $alerts->map(fn ($a) => [
                'disaster' => [
                    'id' => $a->disaster_id,
                    'magnitude' => (float) $a->magnitude,
                    'location' => $a->location_name,
                    'depth_km' => $a->depth_km === null ? null : (float) $a->depth_km,
                    'tsunami_potential' => $a->tsunami_potential,
                    'impact_radius_km' => (int) $a->impact_radius_km,
                    'occurred_at' => \Carbon\CarbonImmutable::parse($a->occurred_at)->utc()->toIso8601String(),
                ],
                'camp' => ['id' => $a->camp_id, 'name' => $a->camp_name, 'code' => $a->camp_code],
                'distance_km' => (float) $a->distance_km,
            ])->values(),
        ]);
    }
}
