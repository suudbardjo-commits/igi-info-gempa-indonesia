<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Menjaga biaya per operasi sinkronisasi. Bukan soal kecepatan jam dinding (itu tergantung mesin), tapi jumlah
 * kueri database per operasi: dari uji beban, inilah yang menentukan berapa banyak operasi per detik yang bisa dilayani.
 */
it('mengukur jumlah kueri per operasi laporan jumlah pengungsi', function () {
    $this->seed(RoleSeeder::class);
    $camp = lgCamp('PSK-A', 113.2, -8.1);
    $user = User::factory()->create()->assignRole(Role::FieldVolunteer->value);
    $camp->volunteers()->attach($user->id);
    Sanctum::actingAs($user);

    $batch = fn (int $n) => collect(range(1, $n))->map(fn ($i) => [
        'id' => (string) Str::uuid7(), 'type' => 'occupancy_report',
        'recorded_at' => now()->subMinutes(100 - $i)->toIso8601String(), 'payload' => ['camp_id' => $camp->id, 'occupants' => $i],
    ])->all();

    // Pemanasan: cache izin dan sejenisnya terisi pada permintaan pertama, bukan bagian dari biaya per operasi.
    $this->postJson('/api/v1/sync/field-logs', ['operations' => $batch(1)])->assertOk();

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $this->postJson('/api/v1/sync/field-logs', ['operations' => $batch(20)])->assertOk();

    $perOperation = $count / 20;
    fwrite(STDERR, sprintf("\nKUERI: %d untuk 20 operasi = %.1f per operasi\n", $count, $perOperation));

    // Batas longgar sebagai pagar pengaman terhadap regresi (mis. kueri N+1 yang tak sengaja ditambahkan).
    expect($perOperation)->toBeLessThan(20);
});
