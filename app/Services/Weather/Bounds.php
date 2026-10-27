<?php

namespace App\Services\Weather;

class Bounds
{
    public static function contains(float $lat, float $lon): bool
    {
        $b = config('weather.bounds');

        return $lat >= $b['south'] && $lat <= $b['north'] && $lon >= $b['west'] && $lon <= $b['east'];
    }
}
