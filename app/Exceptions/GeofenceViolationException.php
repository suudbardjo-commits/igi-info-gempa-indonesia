<?php

namespace App\Exceptions;

use RuntimeException;

class GeofenceViolationException extends RuntimeException
{
    public function __construct(public readonly float $distanceMeters, public readonly int $allowedMeters)
    {
        // Di atas 1 km ditulis dalam km, supaya "207.675 m" tidak terbaca sebagai 207 meter.
        $distance = $distanceMeters >= 1000
            ? number_format($distanceMeters / 1000, 1, ',', '.').' km'
            : number_format($distanceMeters, 0, ',', '.').' m';

        parent::__construct(sprintf(
            'Lokasi Anda sekitar %s dari posko, di luar batas %d m. Serah terima hanya bisa dikonfirmasi di posko.',
            $distance,
            $allowedMeters,
        ));
    }
}
