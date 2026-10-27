<?php

namespace App\Console\Commands;

use App\Services\ExternalNews\ExternalNewsSync;
use Illuminate\Console\Command;
use Throwable;

class FetchExternalNewsCommand extends Command
{
    protected $signature = 'news:fetch {--source= : bmkg atau bnpb; kosong = semua yang aktif}';

    protected $description = 'Ambil berita dari lembaga resmi (BMKG, BNPB)';

    public function handle(ExternalNewsSync $sync): int
    {
        $wanted = $this->option('source');
        $sources = collect(config('disaster.news.sources'))
            ->filter(fn ($s, $key) => $s['enabled'] && (! $wanted || $wanted === $key))
            ->keys();

        if ($sources->isEmpty()) {
            $this->warn('Tidak ada sumber berita yang aktif untuk diambil.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($sources as $source) {
            try {
                $this->info(sprintf('%s: %d berita diperbarui.', strtoupper($source), $sync->sync($source)));
            } catch (Throwable $e) {
                // Satu sumber gagal tidak boleh menghentikan yang lain; berita lama tetap tampil.
                $this->error(strtoupper($source).': '.$e->getMessage());
                $failed++;
            }
        }

        return $failed === $sources->count() ? self::FAILURE : self::SUCCESS;
    }
}
