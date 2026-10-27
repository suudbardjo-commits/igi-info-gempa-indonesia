<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\EvacuationCamp;
use App\Models\ReliefWarehouse;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        // Akun dan data contoh hanya untuk lingkungan lokal (kata sandi semuanya "password").
        if (app()->environment('local')) {
            $this->call(DemoDataSeeder::class);
            $this->seedDemoUsers();
        }
    }

    private function seedDemoUsers(): void
    {
        $users = [];

        foreach ([
            'admin@bencana.test' => ['Admin Lokal', Role::SuperAdmin],
            'operator@bencana.test' => ['Operator BPBD', Role::BpbdOperator],
            'gudang@bencana.test' => ['Pengelola Gudang Surabaya', Role::WarehouseManager],
            'koordinator@bencana.test' => ['Koordinator Posko Lumajang', Role::CampCoordinator],
            'relawan@bencana.test' => ['Relawan Lapangan', Role::FieldVolunteer],
        ] as $email => [$name, $role]) {
            $users[$email] = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);
            $users[$email]->syncRoles([$role->value]);
        }

        // Hak atas gudang dan posko ditentukan oleh siapa pengelola/koordinatornya.
        // Untuk memudahkan uji, satu akun mengelola ketiga gudang contoh; di lapangan tiap gudang punya pengelola sendiri.
        ReliefWarehouse::query()->update(['manager_id' => $users['gudang@bencana.test']->id]);
        EvacuationCamp::where('code', 'PSK-LMJ-01')->update(['coordinator_id' => $users['koordinator@bencana.test']->id]);

        // Relawan bertugas di posko yang sama dengan koordinator demo.
        EvacuationCamp::where('code', 'PSK-LMJ-01')->first()?->volunteers()->syncWithoutDetaching([$users['relawan@bencana.test']->id]);
    }
}
