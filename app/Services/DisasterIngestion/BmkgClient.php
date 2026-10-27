<?php

namespace App\Services\DisasterIngestion;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BmkgClient
{
    /** Nama feed => nama file di data.bmkg.go.id/DataMKG/TEWS. */
    public const FEEDS = [
        'terkini' => 'gempaterkini.json',
        'auto' => 'autogempa.json',
    ];

    /**
     * Ambil gempa dari satu feed. Mengembalikan null kalau data tidak berubah
     * sejak permintaan terakhir (HTTP 304).
     *
     * @return list<array<string, mixed>>|null
     *
     * @throws RuntimeException kalau BMKG membalas error
     */
    public function fetch(string $feed = 'terkini'): ?array
    {
        $url = rtrim(config('disaster.bmkg.base_url'), '/').'/'.self::FEEDS[$feed];
        $etagKey = "bmkg:etag:{$feed}";

        // Firewall BMKG memblokir (403 "Laman diblokir") permintaan dengan
        // `Accept: application/json`, header bawaan klien HTTP Laravel.
        $request = Http::timeout(config('disaster.bmkg.timeout'))
            ->retry(2, 500, throw: false)
            ->accept('*/*')
            ->withUserAgent(config('disaster.http_user_agent'));

        if ($etag = Cache::get($etagKey)) {
            $request = $request->withHeaders(['If-None-Match' => $etag]);
        }

        $response = $request->get($url);

        if ($response->status() === 304) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning('Gagal mengambil data BMKG', ['feed' => $feed, 'status' => $response->status()]);

            throw new RuntimeException("BMKG membalas HTTP {$response->status()} untuk feed {$feed}");
        }

        if ($newEtag = $response->header('ETag')) {
            Cache::put($etagKey, $newEtag, now()->addDay());
        }

        $items = $response->json('Infogempa.gempa') ?? [];

        // autogempa.json berisi satu objek, gempaterkini.json berisi daftar.
        if (isset($items['Magnitude'])) {
            $items = [$items];
        }

        return collect($items)
            ->map(fn (array $item) => $this->normalize($item))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public function normalize(array $item): ?array
    {
        $coords = $this->parseCoordinates($item['Coordinates'] ?? '');

        if ($coords === null || empty($item['DateTime'])) {
            return null;
        }

        try {
            $occurredAt = CarbonImmutable::parse($item['DateTime'])->utc();
        } catch (\Throwable) {
            return null;
        }

        return [
            'external_id' => 'BMKG-'.substr(sha1($item['DateTime'].'|'.$item['Coordinates']), 0, 32),
            'source' => 'bmkg',
            'type' => 'earthquake',
            'magnitude' => isset($item['Magnitude']) ? (float) $item['Magnitude'] : null,
            'depth_km' => isset($item['Kedalaman']) ? (float) preg_replace('/[^0-9.]/', '', $item['Kedalaman']) : null,
            'location_name' => trim($item['Wilayah'] ?? 'Tidak diketahui'),
            'tsunami_potential' => $item['Potensi'] ?? null,
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'occurred_at' => $occurredAt,
        ];
    }

    /** Format BMKG: "lintang,bujur", misalnya "-3.11,102.14". */
    private function parseCoordinates(string $value): ?array
    {
        $parts = array_map('trim', explode(',', $value));

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }

        [$lat, $lng] = [(float) $parts[0], (float) $parts[1]];

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return ['lat' => $lat, 'lng' => $lng];
    }
}
