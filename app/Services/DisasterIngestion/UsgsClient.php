<?php

namespace App\Services\DisasterIngestion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UsgsClient
{
    /**
     * Ambil gempa M4+ di kotak Indonesia dalam beberapa jam terakhir.
     *
     * @return list<array<string, mixed>>|null null kalau permintaan gagal
     */
    public function fetch(): ?array
    {
        $cfg = config('disaster.usgs');

        $response = Http::timeout($cfg['timeout'])
            ->retry(2, 500, throw: false)
            ->withUserAgent(config('disaster.http_user_agent'))
            ->get($cfg['endpoint'], [
                'format' => 'geojson',
                'orderby' => 'time',
                'minmagnitude' => $cfg['min_magnitude'],
                'starttime' => now()->subHours($cfg['lookback_hours'])->utc()->format('Y-m-d\TH:i:s'),
                ...$cfg['bbox'],
            ]);

        if (! $response->successful()) {
            Log::warning('Gagal mengambil data USGS', ['status' => $response->status()]);

            return null;
        }

        return collect($response->json('features') ?? [])
            ->map(fn (array $feature) => $this->normalize($feature))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * GeoJSON RFC 7946: coordinates = [longitude, latitude, kedalaman_km].
     *
     * @param  array<string, mixed>  $feature
     * @return array<string, mixed>|null
     */
    public function normalize(array $feature): ?array
    {
        $coords = $feature['geometry']['coordinates'] ?? null;
        $props = $feature['properties'] ?? [];

        if (! is_array($coords) || count($coords) < 2 || empty($feature['id']) || empty($props['time'])) {
            return null;
        }

        return [
            'external_id' => 'USGS-'.$feature['id'],
            'source' => 'usgs',
            'type' => 'earthquake',
            'magnitude' => isset($props['mag']) ? (float) $props['mag'] : null,
            'depth_km' => isset($coords[2]) ? (float) $coords[2] : null,
            'location_name' => trim($props['place'] ?? 'Tidak diketahui'),
            'tsunami_potential' => ($props['tsunami'] ?? 0) ? 'Peringatan tsunami dikeluarkan (USGS)' : null,
            'lat' => (float) $coords[1],
            'lng' => (float) $coords[0],
            'occurred_at' => CarbonImmutable::createFromTimestampMsUTC((int) $props['time']),
        ];
    }
}
