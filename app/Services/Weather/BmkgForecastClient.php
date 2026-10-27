<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;

/**
 * Prakiraan BMKG untuk satu kode wilayah tingkat IV (adm4: desa/kelurahan), 3 hari per 3 jam.
 * Kode adm4 tidak pernah ditebak dari nama; hanya diterima bila BMKG sendiri menjawabnya.
 */
class BmkgForecastClient
{
    public const ADM4 = '/^\d{2}\.\d{2}\.\d{2}\.\d{4}$/';

    public function __construct(private Upstream $upstream) {}

    /** @return array<string, mixed> */
    public function forecast(string $adm4): array
    {
        $response = $this->upstream->get('bmkg', 'bmkg-forecast', config('weather.bmkg.forecast_url'), ['adm4' => $adm4]);
        $json = $response->json();

        $lokasi = is_array($json) ? ($json['lokasi'] ?? null) : null;
        $days = is_array($json) ? ($json['data'][0]['cuaca'] ?? null) : null;
        if (! is_array($lokasi) || ! is_array($days) || ! isset($lokasi['lat'], $lokasi['lon'])) {
            $this->upstream->fail('bmkg-forecast', 'malformed');
            throw new UpstreamException('malformed', 'Respons BMKG tidak sesuai format yang diharapkan.');
        }
        $this->upstream->ok('bmkg-forecast');

        $zone = Zones::fromLongitude((float) $lokasi['lon']);
        $hourly = [];
        $issued = null;
        foreach ($days as $day) {
            foreach (is_array($day) ? $day : [] as $s) {
                if (! isset($s['utc_datetime'])) {
                    continue;
                }
                $iso = Zones::utcToIso($s['utc_datetime'], $zone);
                if (! $iso) {
                    continue;
                }
                $issued ??= $s['analysis_date'] ?? null;
                $code = isset($s['weather']) ? (int) $s['weather'] : null;
                $hourly[] = [
                    'time' => $iso,
                    'temperature' => $s['t'] ?? null,
                    'feels_like' => null,
                    'humidity' => $s['hu'] ?? null,
                    'rain_probability' => null,
                    'precipitation' => $s['tp'] ?? null,
                    'wind_speed' => $s['ws'] ?? null,
                    'wind_direction' => $s['wd_deg'] ?? null,
                    'cloud_cover' => $s['tcc'] ?? null,
                    'visibility' => $s['vs_text'] ?? null,
                    'uv' => null,
                    'condition' => $s['weather_desc'] ?? null,
                    'icon' => WeatherCodes::bmkgGroup($code),
                ];
            }
        }
        usort($hourly, fn ($a, $b) => strcmp($a['time'], $b['time']));

        if ($hourly === []) {
            throw new UpstreamException('empty', 'BMKG tidak memberikan data prakiraan untuk wilayah ini.');
        }

        // Slot yang sedang berlaku: yang terakhir dimulai sebelum sekarang (slot 3 jam), atau yang pertama bila semua di depan.
        $now = CarbonImmutable::now();
        $current = $hourly[0];
        foreach ($hourly as $slot) {
            if (CarbonImmutable::parse($slot['time'])->lte($now->addMinutes(90))) {
                $current = $slot;
            }
        }

        $daily = [];
        foreach ($hourly as $slot) {
            $date = substr($slot['time'], 0, 10); // tanggal lokasi, sudah di zona yang benar
            $daily[$date]['t'][] = $slot['temperature'];
            $daily[$date]['p'][] = $slot['precipitation'] ?? 0;
            $daily[$date]['c'][] = $slot;
        }
        $dailyOut = [];
        foreach ($daily as $date => $x) {
            $temps = array_filter($x['t'], fn ($v) => $v !== null);
            $mid = $x['c'][intdiv(count($x['c']), 2)];
            $dailyOut[] = [
                'date' => $date,
                'temp_max' => $temps ? max($temps) : null,
                'temp_min' => $temps ? min($temps) : null,
                'precipitation' => round(array_sum($x['p']), 1),
                'rain_probability' => null, 'uv_max' => null, 'sunrise' => null, 'sunset' => null,
                'condition' => $mid['condition'], 'icon' => $mid['icon'],
                'partial' => count($x['c']) < 8,
            ];
        }

        return [
            'provider' => 'bmkg',
            'label' => 'Prakiraan resmi BMKG',
            'attribution' => config('weather.bmkg.attribution'),
            'timezone' => $zone,
            'tz_label' => Zones::label($zone),
            'issued_at' => $issued ? Zones::localToIso($issued, 'UTC') : null,
            'valid_from' => $hourly[0]['time'],
            'valid_to' => end($hourly)['time'],
            'location' => [
                'name' => $lokasi['desa'] ?? null,
                'admin' => implode(', ', array_filter([$lokasi['kecamatan'] ?? null, $lokasi['kotkab'] ?? null, $lokasi['provinsi'] ?? null])),
                'province' => $lokasi['provinsi'] ?? null,
                'latitude' => (float) $lokasi['lat'],
                'longitude' => (float) $lokasi['lon'],
                'adm4' => $lokasi['adm4'] ?? null,
            ],
            'current' => $current,
            'hourly' => $hourly,
            'daily' => $dailyOut,
            'units' => ['temperature' => '°C', 'wind_speed' => 'km/jam', 'precipitation' => 'mm', 'humidity' => '%', 'uv' => 'indeks'],
        ];
    }
}
