<?php

namespace App\Services\DisasterIngestion;

use App\Events\EarthquakeDetectedEvent;
use App\Jobs\CalculateDisasterImpactJob;
use App\Models\DisasterEvent;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Menyimpan kejadian hasil normalisasi dari BMKG/USGS.
 *
 * - Idempoten: external_id yang sama tidak pernah disimpan dua kali, juga saat dua worker balapan.
 * - Gempa yang sama dari sumber lain disimpan, tapi ditandai `canonical_event_id`
 *   dan tidak memicu peringatan kedua.
 */
class DisasterRecorder
{
    /**
     * @param  array<string, mixed>  $data  hasil normalisasi BmkgClient / UsgsClient
     * @return 'created'|'duplicate_source'|'exists'
     */
    public function record(array $data): string
    {
        if (DisasterEvent::where('external_id', $data['external_id'])->exists()) {
            return 'exists';
        }

        $match = $this->findCanonical($data);

        // BMKG diutamakan sebagai kejadian utama (nama lokasi berbahasa Indonesia, otoritas lokal).
        // Kalau laporan USGS sudah lebih dulu masuk, perannya dibalik di bawah.
        $bmkgSupersedes = $match && $data['source'] === 'bmkg' && $match->source->value === 'usgs';
        $canonical = $bmkgSupersedes ? null : $match;

        try {
            $event = DisasterEvent::create([
                'external_id' => $data['external_id'],
                'source' => $data['source'],
                'type' => $data['type'],
                'magnitude' => $data['magnitude'],
                'depth_km' => $data['depth_km'],
                'location_name' => $data['location_name'],
                'tsunami_potential' => $data['tsunami_potential'],
                'coordinates' => DisasterEvent::makePoint($data['lng'], $data['lat']),
                'impact_radius_km' => ImpactRadius::forMagnitude($data['magnitude']),
                // Data dari feed resmi dianggap terverifikasi; input manual tidak lewat jalur ini.
                'is_verified' => true,
                'canonical_event_id' => $canonical?->id,
                'occurred_at' => $data['occurred_at'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'exists';
        }

        if ($bmkgSupersedes) {
            DisasterEvent::whereKey($match->id)->update(['canonical_event_id' => $event->id]);

            return 'duplicate_source';
        }

        if ($canonical) {
            return 'duplicate_source';
        }

        if ($event->isFresh()) {
            event(new EarthquakeDetectedEvent($event, $data['lng'], $data['lat']));
        } else {
            // Data riwayat: dampak tetap dinilai supaya lengkap, tetapi tanpa siaran dan alert.
            CalculateDisasterImpactJob::dispatch($event->id);
        }

        return 'created';
    }

    /**
     * Cari kejadian dari sumber lain yang kemungkinan gempa yang sama:
     * waktu berdekatan, lokasi berdekatan, magnitudo mirip.
     *
     * @param  array<string, mixed>  $data
     */
    private function findCanonical(array $data): ?DisasterEvent
    {
        $cfg = config('disaster.dedupe');
        $time = $data['occurred_at'];

        return DisasterEvent::query()
            ->where('source', '!=', $data['source'])
            ->whereNull('canonical_event_id')
            ->whereBetween('occurred_at', [
                $time->subSeconds($cfg['time_window_seconds']),
                $time->addSeconds($cfg['time_window_seconds']),
            ])
            ->withinRadius($data['lng'], $data['lat'], $cfg['radius_km'])
            ->when(
                $data['magnitude'] !== null,
                fn ($q) => $q->whereRaw('ABS(magnitude - ?) <= ?', [$data['magnitude'], $cfg['magnitude_tolerance']]),
            )
            ->orderBy('created_at')
            ->first();
    }
}
