<?php

namespace App\Services\Weather;

/** Kode cuaca WMO (Open-Meteo) menjadi teks Indonesia dan kelompok ikon. */
class WeatherCodes
{
    private const MAP = [
        0 => ['Cerah', 'clear'], 1 => ['Cerah berawan', 'partly'], 2 => ['Berawan sebagian', 'partly'], 3 => ['Berawan', 'cloud'],
        45 => ['Berkabut', 'fog'], 48 => ['Kabut beku', 'fog'],
        51 => ['Gerimis ringan', 'drizzle'], 53 => ['Gerimis', 'drizzle'], 55 => ['Gerimis lebat', 'drizzle'],
        56 => ['Gerimis beku', 'drizzle'], 57 => ['Gerimis beku lebat', 'drizzle'],
        61 => ['Hujan ringan', 'rain'], 63 => ['Hujan sedang', 'rain'], 65 => ['Hujan lebat', 'rain'],
        66 => ['Hujan beku', 'rain'], 67 => ['Hujan beku lebat', 'rain'],
        71 => ['Salju ringan', 'snow'], 73 => ['Salju', 'snow'], 75 => ['Salju lebat', 'snow'], 77 => ['Butiran salju', 'snow'],
        80 => ['Hujan lokal ringan', 'rain'], 81 => ['Hujan lokal', 'rain'], 82 => ['Hujan lokal lebat', 'rain'],
        85 => ['Hujan salju', 'snow'], 86 => ['Hujan salju lebat', 'snow'],
        95 => ['Hujan petir', 'storm'], 96 => ['Hujan petir dan es', 'storm'], 99 => ['Hujan petir dan es lebat', 'storm'],
    ];

    /** @return array{0: ?string, 1: string} */
    public static function describe(?int $code): array
    {
        return $code === null ? [null, 'unknown'] : (self::MAP[$code] ?? [null, 'unknown']);
    }

    /** Kode BMKG: 0 cerah, 1-2 cerah berawan, 3 berawan, 4 berawan tebal, 5 udara kabur, 10 asap, 45 kabut, 60-63 hujan, 95/97 petir. */
    public static function bmkgGroup(?int $code): string
    {
        return match (true) {
            $code === null => 'unknown',
            $code === 0 => 'clear',
            $code <= 2 => 'partly',
            $code <= 4 => 'cloud',
            $code === 45 || $code === 5 || $code === 10 => 'fog',
            $code >= 95 => 'storm',
            $code >= 60 => 'rain',
            default => 'unknown',
        };
    }
}
