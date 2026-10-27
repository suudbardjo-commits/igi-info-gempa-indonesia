<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;

/** Pencarian nama tempat (GeoNames lewat Open-Meteo). ID GeoNames BUKAN kode wilayah BMKG; tidak ada pencocokan otomatis. */
class Geocoder
{
    public function __construct(private Upstream $upstream) {}

    /** @return array{results: list<array<string, mixed>>, fetched_at: string, cached: bool} */
    public function search(string $q): array
    {
        $key = 'geocode:'.md5(mb_strtolower($q));
        $hit = Cache::get($key);
        if ($hit) {
            return $hit + ['cached' => true];
        }

        $response = $this->upstream->get('geocoder', 'geocoder', config('weather.open_meteo.geocode_url'), [
            'name' => $q, 'count' => 8, 'language' => 'id', 'format' => 'json', 'countryCode' => 'ID',
        ]);
        $json = $response->json();
        if (! is_array($json)) {
            $this->upstream->fail('geocoder', 'malformed');
            throw new UpstreamException('malformed', 'Respons pencarian lokasi tidak sesuai format.');
        }
        $this->upstream->ok('geocoder');

        $results = [];
        foreach ($json['results'] ?? [] as $r) {
            if (! isset($r['latitude'], $r['longitude']) || ! Bounds::contains((float) $r['latitude'], (float) $r['longitude'])) {
                continue;
            }
            $admin = array_values(array_filter([$r['admin2'] ?? null, $r['admin1'] ?? null], fn ($v) => $v && $v !== ($r['name'] ?? null)));
            $results[] = [
                'id' => 'gn'.($r['id'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'admin' => implode(', ', $admin),
                'province' => $r['admin1'] ?? null,
                'latitude' => round((float) $r['latitude'], 4),
                'longitude' => round((float) $r['longitude'], 4),
                'timezone' => $r['timezone'] ?? Zones::fromLongitude((float) $r['longitude']),
                'kind' => $r['feature_code'] ?? null,
                'source' => 'GeoNames',
            ];
        }

        $payload = ['results' => $results, 'fetched_at' => now()->toIso8601String()];
        Cache::put($key, $payload, config('weather.ttl.geocode'));

        return $payload + ['cached' => false];
    }
}
