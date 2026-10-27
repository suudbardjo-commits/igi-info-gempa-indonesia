<?php

namespace App\Console\Commands;

use App\Services\DisasterIngestion\BmkgClient;
use App\Services\DisasterIngestion\DisasterRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

#[Signature('ingest:bmkg {--feed=terkini : terkini (M5+) atau auto (gempa terbaru)}')]
#[Description('Ambil data gempa dari BMKG dan simpan yang baru')]
class IngestBmkgEarthquakesCommand extends Command
{
    public function handle(BmkgClient $client, DisasterRecorder $recorder): int
    {
        $feed = $this->option('feed');

        if (! array_key_exists($feed, BmkgClient::FEEDS)) {
            $this->error("Feed tidak dikenal: {$feed}");

            return self::INVALID;
        }

        try {
            $records = $client->fetch($feed);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Jawaban sukses (juga "tidak berubah") dicatat untuk halaman status; kegagalan tidak memperbarui catatan ini.
        Cache::put('ingest:last_ok:bmkg', now()->toIso8601String(), now()->addDays(7));

        if ($records === null) {
            $this->line('Tidak ada data baru dari BMKG.');

            return self::SUCCESS;
        }

        $counts = collect($records)->countBy(fn ($r) => $recorder->record($r));

        $this->info(sprintf(
            'BMKG %s: %d baru, %d duplikat lintas sumber, %d sudah ada.',
            $feed,
            $counts['created'] ?? 0,
            $counts['duplicate_source'] ?? 0,
            $counts['exists'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
