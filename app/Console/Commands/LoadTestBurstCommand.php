<?php

namespace App\Console\Commands;

use App\Services\DisasterIngestion\DisasterRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

#[Signature('loadtest:burst {--count=40 : jumlah gempa sekaligus} {--wait=180 : detik maksimal menunggu antrean kosong}')]
#[Description('Menyuntikkan sekumpulan gempa baru sekaligus dan mengukur berapa lama jalur peringatan (antrean, dampak, siaran) menuntaskannya')]
class LoadTestBurstCommand extends Command
{
    public function handle(DisasterRecorder $recorder): int
    {
        if (! app()->environment('local')) {
            $this->error('Perintah ini hanya boleh dijalankan di lingkungan lokal.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));
        $failedBefore = DB::table('failed_jobs')->count();
        $deliveriesBefore = DB::table('alert_deliveries')->count();
        $impactBefore = DB::table('disaster_event_camp')->count();

        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            $recorder->record([
                'external_id' => 'LT-'.Str::uuid(),
                'source' => 'manual',
                'type' => 'earthquake',
                'magnitude' => round(5.0 + (($i * 7) % 20) / 10, 1),
                'depth_km' => 10 + ($i % 30),
                'location_name' => "LT burst {$i}",
                'tsunami_potential' => null,
                'lat' => -7.2 - fmod($i * 0.31, 1.3),
                'lng' => 112.0 + fmod($i * 0.43, 2.4),
                'occurred_at' => now()->toImmutable(),
            ]);
        }

        $produced = microtime(true) - $start;
        $this->line(sprintf('Gempa disuntikkan: %d dalam %.2f dtk (%.1f gempa/dtk, termasuk menjadwalkan semua job).', $count, $produced, $count / max($produced, 0.001)));

        // Tunggu sampai semua antrean (menunggu, ditunda, atau sedang diproses) kosong.
        $deadline = $start + (int) $this->option('wait');
        $queue = Queue::connection('redis');

        do {
            $pending = $queue->size('alerts') + $queue->size('default');
            if ($pending === 0) {
                // Beri jeda singkat: job yang baru selesai bisa menjadwalkan job lanjutan (siaran).
                usleep(700_000);
                $pending = $queue->size('alerts') + $queue->size('default');
            }
            usleep(250_000);
        } while ($pending > 0 && microtime(true) < $deadline);

        $total = microtime(true) - $start;
        $drained = $pending === 0;

        $this->table(['Ukuran', 'Hasil'], [
            ['Antrean tuntas', $drained ? sprintf('ya, %.1f dtk sejak mulai', $total) : "TIDAK, masih {$pending} job setelah ".(int) $this->option('wait').' dtk'],
            ['Job gagal', DB::table('failed_jobs')->count() - $failedBefore],
            ['Posko terdampak tercatat', DB::table('disaster_event_camp')->count() - $impactBefore],
            ['Catatan pengiriman alert', DB::table('alert_deliveries')->count() - $deliveriesBefore],
            ['Throughput end-to-end', sprintf('%.1f gempa/dtk', $count / max($total, 0.001))],
        ]);

        return $drained && DB::table('failed_jobs')->count() === $failedBefore ? self::SUCCESS : self::FAILURE;
    }
}
