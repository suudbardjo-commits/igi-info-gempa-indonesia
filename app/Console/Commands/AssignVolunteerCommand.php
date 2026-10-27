<?php

namespace App\Console\Commands;

use App\Models\EvacuationCamp;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('camp:assign-volunteer {camp : kode posko, mis. PSK-LMJ-01} {email : email relawan} {--remove : cabut penugasan}')]
#[Description('Tugaskan (atau cabut) relawan ke sebuah posko supaya bisa melapor untuk posko itu')]
class AssignVolunteerCommand extends Command
{
    public function handle(): int
    {
        $camp = EvacuationCamp::where('code', $this->argument('camp'))->first();
        $user = User::where('email', $this->argument('email'))->first();

        if (! $camp || ! $user) {
            $this->error(! $camp ? 'Posko tidak ditemukan.' : 'Pengguna tidak ditemukan.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            $camp->volunteers()->detach($user->id);
            $this->info("{$user->name} tidak lagi bertugas di {$camp->name}.");

            return self::SUCCESS;
        }

        if (! $user->can('field-report.create')) {
            $this->warn("{$user->name} belum punya peran yang boleh melapor (relawan lapangan atau koordinator posko).");
        }

        $camp->volunteers()->syncWithoutDetaching([$user->id]);
        $this->info("{$user->name} ditugaskan ke {$camp->name}.");

        return self::SUCCESS;
    }
}
