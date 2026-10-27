<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Cache;

/**
 * Menggabungkan penyedia: cache segar, satu permintaan ke penyedia per kunci (kunci kunci), data basi berlabel bila
 * penyedia gagal, dan cadangan Open-Meteo untuk lokasi BMKG yang gagal (dilabeli jelas, tidak diam-diam).
 */
class WeatherService
{
    public function __construct(private OpenMeteoClient $openMeteo, private BmkgForecastClient $bmkg) {}

    /** @return array<string, mixed> */
    public function byCoordinates(float $lat, float $lon, ?string $name = null): array
    {
        $lat = round($lat, 2);
        $lon = round($lon, 2);
        $data = $this->cached("weather:om:{$lat}:{$lon}", fn () => $this->openMeteo->forecast($lat, $lon));
        $data['location'] = ['name' => $name, 'admin' => null, 'latitude' => $lat, 'longitude' => $lon, 'adm4' => null] + ['timezone' => $data['timezone']];

        return $data;
    }

    /** @return array<string, mixed> */
    public function byAdm4(string $adm4, bool $fallback = true): array
    {
        try {
            $data = $this->cached("weather:bmkg:{$adm4}", fn () => $this->bmkg->forecast($adm4));
            $data['location']['timezone'] = $data['timezone'];

            return $data;
        } catch (UpstreamException $e) {
            if (! $fallback || ! in_array($e->reason, ['unreachable', 'upstream_error', 'rate_limited', 'busy', 'malformed'], true)) {
                throw $e;
            }
            // Koordinat wilayah untuk cadangan hanya diketahui bila prakiraan BMKG pernah berhasil.
            $known = Cache::get("weather:bmkg:{$adm4}:loc");
            if (! $known) {
                throw $e;
            }
            $data = $this->byCoordinates($known['latitude'], $known['longitude'], $known['name']);
            $data['fallback'] = ['from' => 'bmkg', 'reason' => $e->reason, 'note' => 'Prakiraan BMKG sedang tidak tersedia; ditampilkan estimasi model Open-Meteo untuk titik yang sama.'];

            return $data;
        }
    }

    /** @param callable(): array<string, mixed> $fetch */
    private function cached(string $key, callable $fetch): array
    {
        $fresh = config('weather.ttl.forecast');
        $stored = Cache::get($key);
        if ($stored && $this->age($stored) < $fresh) {
            return $this->decorate($stored, false);
        }

        $lock = Cache::lock("lock:{$key}", 10);
        try {
            // Menunggu pemanggil lain yang sedang mengambil data yang sama, lalu membaca hasilnya.
            $lock->block(6);
            $stored = Cache::get($key);
            if ($stored && $this->age($stored) < $fresh) {
                return $this->decorate($stored, false);
            }

            $data = $fetch();
            $data['fetched_at'] = now()->toIso8601String();
            Cache::put($key, $data, $fresh + config('weather.stale_for'));
            if (isset($data['location']['adm4'])) {
                Cache::put("{$key}:loc", ['latitude' => $data['location']['latitude'], 'longitude' => $data['location']['longitude'], 'name' => $data['location']['name']], 86400 * 30);
            }

            return $this->decorate($data, false);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            if ($stored) {
                return $this->decorate($stored, true, 'busy');
            }
            throw new UpstreamException('busy', 'Permintaan yang sama sedang diproses; coba lagi sebentar lagi.');
        } catch (UpstreamException $e) {
            if ($stored) {
                return $this->decorate($stored, true, $e->reason);
            }
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    private function age(array $data): int
    {
        return (int) now()->diffInSeconds(\Carbon\CarbonImmutable::parse($data['fetched_at']), true);
    }

    private function decorate(array $data, bool $refreshFailed, ?string $reason = null): array
    {
        $age = $this->age($data);
        $data['cache_age_seconds'] = $age;
        $data['is_stale'] = $refreshFailed || $age > config('weather.ttl.forecast') * 2;
        if ($refreshFailed) {
            $data['refresh_failed'] = $reason;
        }

        return $data;
    }
}
