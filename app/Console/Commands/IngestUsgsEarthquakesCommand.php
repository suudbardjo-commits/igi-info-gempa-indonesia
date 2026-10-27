<?php

namespace App\Console\Commands;

use App\Services\DisasterIngestion\DisasterRecorder;
use App\Services\DisasterIngestion\UsgsClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('ingest:usgs')]
#[Description('Ambil data gempa wilayah Indonesia dari USGS dan simpan yang baru')]
class IngestUsgsEarthquakesCommand extends Command
{
    public function handle(UsgsClient $client, DisasterRecorder $recorder): int
    {
        $records = $client->fetch();

        if ($records === null) {
            $this->warn('Gagal mengambil data USGS.');

            return self::FAILURE;
        }

        Cache::put('ingest:last_ok:usgs', now()->toIso8601String(), now()->addDays(7));

        $counts = collect($records)->countBy(fn ($r) => $recorder->record($r));

        $this->info(sprintf(
            'USGS: %d baru, %d duplikat lintas sumber, %d sudah ada.',
            $counts['created'] ?? 0,
            $counts['duplicate_source'] ?? 0,
            $counts['exists'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
