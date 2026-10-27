<?php

namespace App\Services\DisasterIngestion;

class ImpactRadius
{
    public static function forMagnitude(?float $magnitude): int
    {
        $magnitude ??= 0.0;

        foreach (config('disaster.impact_radius') as $rule) {
            if ($magnitude >= $rule['min_magnitude']) {
                return (int) $rule['radius_km'];
            }
        }

        return 15;
    }
}
