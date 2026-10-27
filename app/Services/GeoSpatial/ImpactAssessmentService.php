<?php

namespace App\Services\GeoSpatial;

use App\Models\DisasterEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImpactAssessmentService
{
    /**
     * Posko yang berada dalam radius dampak suatu kejadian, urut dari yang terdekat.
     * Jarak dihitung di atas bola bumi (geography), satuan kilometer.
     *
     * @return Collection<int, object{id: string, name: string, code: string, capacity_people: int, current_occupants: int, distance_km: float}>
     */
    public function affectedCamps(DisasterEvent $disaster): Collection
    {
        return DB::table('evacuation_camps as c')
            ->crossJoin('disaster_events as d')
            ->where('d.id', $disaster->id)
            ->where('c.status', '!=', 'closed')
            ->whereRaw('ST_DWithin(c.coordinates::geography, d.coordinates::geography, d.impact_radius_km * 1000)')
            ->selectRaw('c.id, c.name, c.code, c.capacity_people, c.current_occupants')
            ->selectRaw('ROUND((ST_Distance(c.coordinates::geography, d.coordinates::geography) / 1000)::numeric, 2) AS distance_km')
            ->orderBy('distance_km')
            ->get();
    }

    /** Simpan hasil ke tabel pivot (menimpa hasil penilaian sebelumnya). */
    public function assess(DisasterEvent $disaster): Collection
    {
        $camps = $this->affectedCamps($disaster);

        DB::transaction(function () use ($disaster, $camps) {
            DB::table('disaster_event_camp')->where('disaster_event_id', $disaster->id)->delete();

            if ($camps->isNotEmpty()) {
                DB::table('disaster_event_camp')->insert(
                    $camps->map(fn ($c) => [
                        'disaster_event_id' => $disaster->id,
                        'camp_id' => $c->id,
                        'distance_km' => $c->distance_km,
                        'assessed_at' => now(),
                    ])->all(),
                );
            }
        });

        return $camps;
    }
}
