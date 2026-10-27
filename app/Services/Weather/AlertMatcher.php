<?php

namespace App\Services\Weather;

/** Mencocokkan peringatan dengan titik: poligon bila ada ("polygon"), atau nama provinsi pada deskripsi area ("province"). */
class AlertMatcher
{
    /** @return 'polygon'|'province'|null */
    public function match(array $alert, ?float $lat, ?float $lon, ?string $province): ?string
    {
        if ($lat !== null && $lon !== null) {
            foreach ($alert['polygons'] as $ring) {
                if ($this->inside($lon, $lat, $ring)) {
                    return 'polygon';
                }
            }
        }

        if ($province && $alert['area'] && mb_stripos($alert['area'], $province) !== false) {
            return 'province';
        }

        return null;
    }

    /** Ray casting; cincin GeoJSON [bujur, lintang]. */
    private function inside(float $x, float $y, array $ring): bool
    {
        $in = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $in = ! $in;
            }
        }

        return $in;
    }
}
