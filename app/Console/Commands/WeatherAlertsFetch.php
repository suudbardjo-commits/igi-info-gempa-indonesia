<?php

namespace App\Console\Commands;

use App\Services\Weather\AlertFeed;
use Illuminate\Console\Command;

class WeatherAlertsFetch extends Command
{
    protected $signature = 'weather:alerts';

    protected $description = 'Ambil peringatan dini cuaca BMKG (RSS + CAP) ke cache';

    public function handle(AlertFeed $feed): int
    {
        $store = $feed->refresh();
        if (! $store || ($store['stale'] ?? false)) {
            $this->error('Peringatan dini belum berhasil diperbarui'.($store ? ' ('.$store['error'].'); data lama dipertahankan' : ''));

            return self::FAILURE;
        }
        $this->info(count($store['alerts']).' peringatan tercatat');

        return self::SUCCESS;
    }
}
