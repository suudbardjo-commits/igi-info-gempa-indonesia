<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create {email} {name} {--role= : super-admin, bpbd-operator, warehouse-manager, camp-coordinator, atau field-volunteer}';

    protected $description = 'Buat akun petugas yang langsung aktif (untuk admin; peran operator dan admin hanya dibuat lewat sini)';

    public function handle(): int
    {
        $roles = array_map(fn (Role $r) => $r->value, Role::cases());
        $role = $this->option('role') ?: $this->choice('Peran', $roles);

        if (! in_array($role, $roles, true)) {
            $this->error('Peran tidak dikenal: '.$role);

            return self::FAILURE;
        }

        // Kata sandi sengaja ditanyakan, bukan argumen: argumen tersimpan di riwayat perintah shell.
        $password = $this->secret('Kata sandi (minimal 10 karakter, huruf dan angka)');

        $v = Validator::make(
            ['email' => $this->argument('email'), 'name' => $this->argument('name'), 'password' => $password],
            ['email' => ['required', 'email', 'unique:users,email'], 'name' => ['required', 'min:3', 'max:100'], 'password' => ['required', new StrongPassword]],
        );

        if ($v->fails()) {
            foreach ($v->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create(['name' => $this->argument('name'), 'email' => strtolower($this->argument('email')), 'password' => $password]);
        $user->assignRole($role);

        $this->info("Akun {$user->email} dibuat sebagai {$role}. Untuk relawan atau koordinator, tugaskan posko: camp:assign-volunteer.");

        return self::SUCCESS;
    }
}
