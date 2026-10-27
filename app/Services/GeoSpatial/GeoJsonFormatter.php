<?php

namespace App\Services\GeoSpatial;

use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use Illuminate\Support\Collection;

/**
 * Mengubah model (yang dimuat dengan scope withCoordinates) menjadi GeoJSON RFC 7946.
 * Urutan koordinat GeoJSON: [longitude, latitude].
 */
class GeoJsonFormatter
{
    /** @param Collection<int, DisasterEvent> $events */
    public function disasters(Collection $events): array
    {
        return $this->collection($events->map(fn (DisasterEvent $e) => $this->disasterFeature($e)));
    }

    public function disasterFeature(DisasterEvent $e): array
    {
        return $this->feature($e->id, (float) $e->longitude, (float) $e->latitude, [
            'source' => $e->source->value,
            'type' => $e->type->value,
            'magnitude' => $e->magnitude,
            'depth_km' => $e->depth_km,
            'location' => $e->location_name,
            'tsunami_potential' => $e->tsunami_potential,
            'impact_radius_km' => $e->impact_radius_km,
            'is_verified' => $e->is_verified,
            'occurred_at' => $e->occurred_at->toIso8601String(),
        ]);
    }

    /**
     * Hanya data yang aman untuk publik: tanpa nomor telepon koordinator.
     *
     * @param  Collection<int, EvacuationCamp>  $camps
     */
    public function camps(Collection $camps): array
    {
        return $this->collection($camps->map(fn (EvacuationCamp $c) => $this->feature(
            $c->id,
            (float) $c->longitude,
            (float) $c->latitude,
            [
                'name' => $c->name,
                'code' => $c->code,
                'status' => $c->status->value,
                'capacity_people' => $c->capacity_people,
                'current_occupants' => $c->current_occupants,
            ],
        )));
    }

    private function feature(string $id, float $lng, float $lat, array $properties): array
    {
        return [
            'type' => 'Feature',
            'id' => $id,
            'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'properties' => $properties,
        ];
    }

    private function collection(Collection $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features->values()->all()];
    }
}
