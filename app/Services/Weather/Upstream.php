<?php

namespace App\Services\Weather;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Satu pintu keluar ke penyedia data: batas waktu, satu kali ulang, hormati 429/Retry-After, batas laju internal,
 * batas ukuran respons, dan pencatatan kesehatan sumber (tanpa menyimpan URL berisi data pengguna).
 */
class Upstream
{
    /** @param array<string, scalar> $query */
    public function get(string $provider, string $healthKey, string $url, array $query = []): Response
    {
        $blockedUntil = Cache::get("upstream:blocked:{$provider}");
        if ($blockedUntil && $blockedUntil > time()) {
            $this->fail($healthKey, 'rate_limited');
            throw new UpstreamException('rate_limited', 'Penyedia data sedang membatasi permintaan. Coba lagi sebentar lagi.');
        }

        $limit = (int) config("weather.upstream_per_minute.{$provider}", 60);
        if (! RateLimiter::attempt("upstream:{$provider}", $limit, fn () => true, 60)) {
            throw new UpstreamException('busy', 'Terlalu banyak permintaan ke penyedia data; coba lagi sebentar lagi.');
        }

        try {
            $response = Http::timeout(config('weather.timeout'))
                ->retry(1, 300, throw: false)
                ->accept('*/*')
                ->withUserAgent(config('weather.user_agent'))
                ->get($url, $query);
        } catch (ConnectionException) {
            $this->fail($healthKey, 'unreachable');
            throw new UpstreamException('unreachable', 'Penyedia data tidak dapat dihubungi.');
        }

        if ($response->status() === 429) {
            $wait = min(900, max(30, (int) $response->header('Retry-After') ?: 60));
            Cache::put("upstream:blocked:{$provider}", time() + $wait, $wait);
            $this->fail($healthKey, 'rate_limited');
            throw new UpstreamException('rate_limited', 'Penyedia data membatasi permintaan.');
        }

        // 404 berarti penyedia tidak punya data untuk permintaan ini; bukan kerusakan sumber.
        if ($response->status() === 404) {
            throw new UpstreamException('not_found', 'Penyedia tidak memiliki data untuk wilayah ini.');
        }

        if (! $response->successful()) {
            $this->fail($healthKey, 'http_'.$response->status());
            throw new UpstreamException('upstream_error', 'Penyedia data membalas galat ('.$response->status().').');
        }

        if (strlen($response->body()) > config('weather.max_bytes')) {
            $this->fail($healthKey, 'too_large');
            throw new UpstreamException('too_large', 'Respons penyedia data terlalu besar.');
        }

        return $response;
    }

    public function ok(string $key): void
    {
        Cache::forever("source:last_ok:{$key}", now()->toIso8601String());
        Cache::forget("source:last_fail:{$key}");
    }

    public function fail(string $key, string $code): void
    {
        Cache::forever("source:last_fail:{$key}", ['at' => now()->toIso8601String(), 'code' => $code]);
    }
}
