<?php

namespace App\Services\Weather;

/** Prakiraan per-jam dan 7 hari berdasarkan koordinat. Data model numerik, bukan pengamatan stasiun. */
class OpenMeteoClient
{
    public function __construct(private Upstream $upstream) {}

    /** @return array<string, mixed> bentuk seragam, tanpa metadata cache */
    public function forecast(float $lat, float $lon): array
    {
        $response = $this->upstream->get('open-meteo', 'open-meteo', config('weather.open_meteo.forecast_url'), [
            'latitude' => $lat,
            'longitude' => $lon,
            'timezone' => 'auto',
            'forecast_days' => 7,
            'temperature_unit' => 'celsius',
            'wind_speed_unit' => 'kmh',
            'precipitation_unit' => 'mm',
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m,wind_direction_10m,precipitation,cloud_cover',
            'hourly' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation_probability,precipitation,weather_code,wind_speed_10m,uv_index',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,uv_index_max,sunrise,sunset',
        ]);

        $json = $response->json();
        if (! is_array($json) || ! isset($json['current']['time'], $json['hourly']['time'], $json['daily']['time'])) {
            $this->upstream->fail('open-meteo', 'malformed');
            throw new UpstreamException('malformed', 'Respons Open-Meteo tidak sesuai format yang diharapkan.');
        }

        $zone = is_string($json['timezone'] ?? null) ? $json['timezone'] : Zones::fromLongitude($lon);
        $this->upstream->ok('open-meteo');

        $c = $json['current'];
        [$text, $group] = WeatherCodes::describe(isset($c['weather_code']) ? (int) $c['weather_code'] : null);

        $hourly = [];
        $h = $json['hourly'];
        foreach ($h['time'] as $i => $t) {
            $code = $h['weather_code'][$i] ?? null;
            [$ht, $hg] = WeatherCodes::describe($code === null ? null : (int) $code);
            $hourly[] = [
                'time' => Zones::localToIso($t, $zone),
                'temperature' => $h['temperature_2m'][$i] ?? null,
                'feels_like' => $h['apparent_temperature'][$i] ?? null,
                'humidity' => $h['relative_humidity_2m'][$i] ?? null,
                'rain_probability' => $h['precipitation_probability'][$i] ?? null,
                'precipitation' => $h['precipitation'][$i] ?? null,
                'wind_speed' => $h['wind_speed_10m'][$i] ?? null,
                'uv' => $h['uv_index'][$i] ?? null,
                'condition' => $ht, 'icon' => $hg,
            ];
        }

        $daily = [];
        $d = $json['daily'];
        foreach ($d['time'] as $i => $date) {
            $code = $d['weather_code'][$i] ?? null;
            [$dt, $dg] = WeatherCodes::describe($code === null ? null : (int) $code);
            $daily[] = [
                'date' => $date,
                'temp_max' => $d['temperature_2m_max'][$i] ?? null,
                'temp_min' => $d['temperature_2m_min'][$i] ?? null,
                'precipitation' => $d['precipitation_sum'][$i] ?? null,
                'rain_probability' => $d['precipitation_probability_max'][$i] ?? null,
                'uv_max' => $d['uv_index_max'][$i] ?? null,
                'sunrise' => isset($d['sunrise'][$i]) ? Zones::localToIso($d['sunrise'][$i], $zone) : null,
                'sunset' => isset($d['sunset'][$i]) ? Zones::localToIso($d['sunset'][$i], $zone) : null,
                'condition' => $dt, 'icon' => $dg,
            ];
        }

        // Peluang hujan jam ini diambil dari deret per-jam pada jam yang sama dengan "current".
        $hourKey = substr($c['time'], 0, 13);
        $idx = null;
        foreach ($h['time'] as $i => $t) {
            if (str_starts_with($t, $hourKey)) {
                $idx = $i;
                break;
            }
        }

        return [
            'provider' => 'open-meteo',
            'label' => 'Estimasi cuaca saat ini',
            'attribution' => config('weather.open_meteo.attribution'),
            'timezone' => $zone,
            'tz_label' => Zones::label($zone),
            'issued_at' => null,
            'grid' => ['latitude' => $json['latitude'] ?? $lat, 'longitude' => $json['longitude'] ?? $lon],
            'valid_from' => $hourly[0]['time'] ?? null,
            'valid_to' => end($hourly)['time'] ?? null,
            'current' => [
                'time' => Zones::localToIso($c['time'], $zone),
                'temperature' => $c['temperature_2m'] ?? null,
                'feels_like' => $c['apparent_temperature'] ?? null,
                'humidity' => $c['relative_humidity_2m'] ?? null,
                'wind_speed' => $c['wind_speed_10m'] ?? null,
                'wind_direction' => $c['wind_direction_10m'] ?? null,
                'precipitation' => $c['precipitation'] ?? null,
                'cloud_cover' => $c['cloud_cover'] ?? null,
                'rain_probability' => $idx === null ? null : ($h['precipitation_probability'][$idx] ?? null),
                'uv' => $idx === null ? null : ($h['uv_index'][$idx] ?? null),
                'condition' => $text, 'icon' => $group,
            ],
            'hourly' => $hourly,
            'daily' => $daily,
            'units' => ['temperature' => '°C', 'wind_speed' => 'km/jam', 'precipitation' => 'mm', 'humidity' => '%', 'uv' => 'indeks'],
        ];
    }
}
