<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\EvacuationCamp;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('loadtest:prepare {--users=30 : jumlah relawan uji} {--camps=10 : jumlah posko uji}')]
#[Description('Membuat relawan dan posko palsu untuk uji beban (hanya lokal). Bersihkan dengan loadtest:cleanup')]
class LoadTestPrepareCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Perintah ini hanya boleh dijalankan di lingkungan lokal.');

            return self::FAILURE;
        }

        $nCamps = max(1, (int) $this->option('camps'));
        $nUsers = max(1, (int) $this->option('users'));

        $camps = collect(range(1, $nCamps))->map(fn ($i) => EvacuationCamp::firstOrCreate(
            ['code' => sprintf('LT-%02d', $i)],
            [
                'name' => "Posko uji beban {$i}",
                // Tersebar di Jawa Timur.
                'coordinates' => EvacuationCamp::makePoint(112.0 + fmod($i * 0.37, 2.2), -7.2 - fmod($i * 0.23, 1.2)),
                'capacity_people' => 500,
                'contact_phone' => '0',
            ],
        ));

        $hash = Hash::make('password');   // sekali saja: bcrypt sengaja lambat

        foreach (range(1, $nUsers) as $i) {
            $user = User::firstOrCreate(
                ['email' => "loadtest{$i}@bencana.test"],
                ['name' => "Relawan uji {$i}", 'password' => 'password'],
            );
            $user->syncRoles([Role::FieldVolunteer->value]);

            // Tiap relawan bertugas di dua posko.
            $user->forceFill(['password' => $hash])->save();
            foreach ([($i - 1) % $nCamps, $i % $nCamps] as $idx) {
                $camps[$idx]->volunteers()->syncWithoutDetaching([$user->id]);
            }
        }

        $this->info("Siap: {$nUsers} relawan (loadtest1..{$nUsers}@bencana.test, kata sandi \"password\") dan {$nCamps} posko (LT-01..).");

        return self::SUCCESS;
    }
}
