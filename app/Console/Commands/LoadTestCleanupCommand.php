<?php

namespace App\Console\Commands;

use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loadtest:cleanup')]
#[Description('Menghapus relawan, posko, laporan, dan gempa yang dibuat untuk uji beban')]
class LoadTestCleanupCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Perintah ini hanya boleh dijalankan di lingkungan lokal.');

            return self::FAILURE;
        }

        // Laporan, operasi sinkronisasi, token, penugasan, dan catatan alert ikut terhapus lewat cascade.
        $users = User::where('email', 'like', 'loadtest%@bencana.test')->delete();
        $camps = EvacuationCamp::where('code', 'like', 'LT-%')->delete();
        $quakes = DisasterEvent::where('external_id', 'like', 'LT-%')->orWhere('location_name', 'like', 'LT %')->delete();

        $this->info("Dihapus: {$users} relawan, {$camps} posko, {$quakes} gempa uji.");

        return self::SUCCESS;
    }
}
