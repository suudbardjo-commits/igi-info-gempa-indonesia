<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;

/** Zona waktu Indonesia dan label WIB/WITA/WIT. Semua waktu keluar sebagai ISO 8601 dengan offset lokasi. */
class Zones
{
    private const LABEL = [
        'Asia/Jakarta' => 'WIB', 'Asia/Pontianak' => 'WIB',
        'Asia/Makassar' => 'WITA',
        'Asia/Jayapura' => 'WIT',
    ];

    public static function label(string $zone): string
    {
        return self::LABEL[$zone] ?? $zone;
    }

    /** Zona dari koordinat kasar bila penyedia tidak memberikannya. */
    public static function fromLongitude(float $lng): string
    {
        return match (true) {
            $lng < 114.5 => 'Asia/Jakarta',
            $lng < 127.5 => 'Asia/Makassar',
            default => 'Asia/Jayapura',
        };
    }

    /** "2026-10-25T11:00" (waktu dinding lokasi) menjadi ISO dengan offset yang benar. */
    public static function localToIso(string $wallTime, string $zone): ?string
    {
        try {
            return CarbonImmutable::parse($wallTime, $zone)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function utcToIso(string $utc, string $zone): ?string
    {
        try {
            return CarbonImmutable::parse($utc, 'UTC')->setTimezone($zone)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
