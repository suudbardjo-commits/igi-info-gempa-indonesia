<?php

namespace App\Services\Alerts;

use App\Models\DisasterEvent;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Collection;

/**
 * Menyusun teks peringatan untuk Telegram (parse_mode HTML). Semua teks yang berasal dari luar
 * (nama lokasi dari BMKG/USGS, nama posko) di-escape; hanya tag buatan kita sendiri yang lolos.
 */
class AlertMessageBuilder
{
    public function forCamp(DisasterEvent $d, string $campName, float $distanceKm): string
    {
        $lines = [
            "<b>Peringatan gempa M {$this->mag($d)}</b>",
            $this->e($d->location_name).' (sumber: '.$this->source($d).')',
            $this->when($d).', kedalaman '.$this->depth($d).'.',
            '',
            'Posko <b>'.$this->e($campName).'</b> berada sekitar '.$this->km($distanceKm)." km dari pusat gempa, di dalam perkiraan radius dampak {$d->impact_radius_km} km.",
        ];

        $this->addTsunami($lines, $d);

        $lines[] = '';
        $lines[] = 'Segera periksa kondisi pengungsi dan bangunan posko, lalu laporkan lewat aplikasi.';
        $lines[] = $this->disclaimer();

        return implode("\n", $lines);
    }

    /** @param Collection<int, object{name: string, distance_km: float|string}> $camps */
    public function forOperator(DisasterEvent $d, Collection $camps): string
    {
        $lines = [
            "<b>Gempa M {$this->mag($d)}</b>",
            $this->e($d->location_name).' (sumber: '.$this->source($d).')',
            $this->when($d).', kedalaman '.$this->depth($d).", perkiraan radius dampak {$d->impact_radius_km} km.",
            '',
        ];

        if ($camps->isEmpty()) {
            $lines[] = 'Tidak ada posko terdaftar di dalam radius dampak.';
        } else {
            $lines[] = "{$camps->count()} posko terdaftar berada di dalam radius dampak:";

            foreach ($camps->take(5) as $camp) {
                $lines[] = '- '.$this->e($camp->name).', '.$this->km((float) $camp->distance_km).' km';
            }

            if ($camps->count() > 5) {
                $lines[] = '- dan '.($camps->count() - 5).' posko lainnya';
            }
        }

        $this->addTsunami($lines, $d);

        $lines[] = '';
        $lines[] = $this->disclaimer();

        return implode("\n", $lines);
    }

    /** @param list<string> $lines */
    private function addTsunami(array &$lines, DisasterEvent $d): void
    {
        if ($d->tsunami_potential && preg_match('/tsunami/i', $d->tsunami_potential) && ! preg_match('/tidak/i', $d->tsunami_potential)) {
            $lines[] = '';
            $lines[] = '<b>Berpotensi tsunami:</b> '.$this->e($d->tsunami_potential);
        }
    }

    private function disclaimer(): string
    {
        return '<i>Perkiraan radius berasal dari magnitudo, bukan peta guncangan resmi. Ikuti arahan BMKG dan BPBD.</i>';
    }

    private function mag(DisasterEvent $d): string
    {
        return number_format((float) $d->magnitude, 1, ',', '');
    }

    private function depth(DisasterEvent $d): string
    {
        return $d->depth_km === null ? 'belum diketahui' : number_format($d->depth_km, 0, ',', '.').' km';
    }

    private function km(float $km): string
    {
        return number_format($km, 1, ',', '.');
    }

    private function when(DisasterEvent $d): string
    {
        return 'Terjadi '.$d->occurred_at->copy()->setTimezone('Asia/Jakarta')->locale('id')->translatedFormat('j F Y, H:i').' WIB';
    }

    private function source(DisasterEvent $d): string
    {
        return ['bmkg' => 'BMKG', 'usgs' => 'USGS', 'manual' => 'input petugas'][$d->source->value] ?? $d->source->value;
    }

    private function e(string $text): string
    {
        return TelegramClient::escape($text);
    }
}
