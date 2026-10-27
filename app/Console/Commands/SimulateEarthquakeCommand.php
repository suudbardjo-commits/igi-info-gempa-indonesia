<?php

namespace App\Console\Commands;

use App\Services\DisasterIngestion\DisasterRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('quake:simulate {--lat=-8.1335} {--lng=113.2246} {--mag=6.1} {--depth=20} {--name=Simulasi: dekat Lumajang, Jawa Timur} {--tsunami : Tandai berpotensi tsunami}')]
#[Description('Membuat gempa palsu untuk menguji siaran langsung dan penilaian dampak (hanya lokal)')]
class SimulateEarthquakeCommand extends Command
{
    public function handle(DisasterRecorder $recorder): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('Perintah ini hanya boleh dijalankan di lingkungan lokal.');

            return self::FAILURE;
        }

        $result = $recorder->record([
            'external_id' => 'SIM-'.Str::uuid(),
            'source' => 'manual',
            'type' => 'earthquake',
            'magnitude' => (float) $this->option('mag'),
            'depth_km' => (float) $this->option('depth'),
            'location_name' => $this->option('name'),
            'tsunami_potential' => $this->option('tsunami') ? 'Berpotensi tsunami (simulasi)' : 'Tidak berpotensi tsunami',
            'lat' => (float) $this->option('lat'),
            'lng' => (float) $this->option('lng'),
            'occurred_at' => now()->toImmutable(),
        ]);

        $this->info("Gempa simulasi: {$result}.");

        return self::SUCCESS;
    }
}
