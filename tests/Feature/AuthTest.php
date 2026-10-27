<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

it('memberi token dan daftar peran saat login benar', function () {
    $user = User::factory()->create(['email' => 'gudang@bencana.test', 'password' => 'rahasia-123']);
    $user->assignRole(Role::WarehouseManager->value);

    $res = $this->postJson('/api/v1/auth/token', [
        'email' => 'gudang@bencana.test',
        'password' => 'rahasia-123',
        'device_name' => 'ponsel',
    ])->assertOk();

    expect($res->json('token'))->not->toBeEmpty()
        ->and($res->json('user.roles'))->toBe(['warehouse-manager']);
});

it('menolak kata sandi salah dan email tidak dikenal dengan pesan yang sama', function () {
    User::factory()->create(['email' => 'a@bencana.test', 'password' => 'benar']);

    $salah = $this->postJson('/api/v1/auth/token', ['email' => 'a@bencana.test', 'password' => 'salah', 'device_name' => 'x']);
    $takAda = $this->postJson('/api/v1/auth/token', ['email' => 'tidak@ada.test', 'password' => 'apa-saja', 'device_name' => 'x']);

    $salah->assertUnprocessable();
    $takAda->assertUnprocessable();
    expect($takAda->json('errors.email'))->toBe($salah->json('errors.email'));
});

it('menutup endpoint terlindungi bagi yang belum login', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

it('mencabut token saat keluar', function () {
    $user = User::factory()->create(['password' => 'rahasia-123']);
    $token = $this->postJson('/api/v1/auth/token', [
        'email' => $user->email, 'password' => 'rahasia-123', 'device_name' => 'x',
    ])->json('token');

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $user->id);
    $this->withToken($token)->deleteJson('/api/v1/auth/token')->assertOk();

    // Token yang sama tidak berlaku lagi.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
});
