<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function akun(Role $role, string $password = 'rahasia-123'): User
{
    return User::factory()->create(['password' => $password])->assignRole($role->value);
}

it('mengarahkan tamu yang membuka konsol ke halaman masuk', function () {
    $this->get('/logistik')->assertRedirect('/masuk');
    $this->get('/logistik/pengiriman')->assertRedirect('/masuk');
});

it('menampilkan halaman masuk, dan mengalihkan yang sudah masuk ke konsol', function () {
    $this->get('/masuk')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Masuk'));

    $this->actingAs(akun(Role::WarehouseManager))->get('/masuk')->assertRedirect('/logistik');
});

it('masuk dengan kata sandi benar lalu diarahkan ke konsol', function () {
    $user = akun(Role::WarehouseManager);

    $this->post('/masuk', ['email' => $user->email, 'password' => 'rahasia-123'])->assertRedirect('/logistik');
    $this->assertAuthenticatedAs($user);
});

it('mengembalikan ke tujuan semula setelah masuk', function () {
    $user = akun(Role::BpbdOperator);

    $this->get('/logistik/audit')->assertRedirect('/masuk');
    $this->post('/masuk', ['email' => $user->email, 'password' => 'rahasia-123'])->assertRedirect('/logistik/audit');
});

it('menolak kata sandi salah dengan pesan yang sama untuk email tak dikenal', function () {
    $user = akun(Role::WarehouseManager);

    $a = $this->from('/masuk')->post('/masuk', ['email' => $user->email, 'password' => 'salah']);
    $b = $this->from('/masuk')->post('/masuk', ['email' => 'tidak@ada.test', 'password' => 'salah']);

    $a->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
    $b->assertSessionHasErrors(['email' => 'Email atau kata sandi salah.']);
    $this->assertGuest();
});

it('memblokir sementara setelah lima percobaan gagal', function () {
    $user = akun(Role::WarehouseManager);

    foreach (range(1, 5) as $i) {
        $this->post('/masuk', ['email' => $user->email, 'password' => 'salah']);
    }

    // Percobaan keenam ditolak walau kata sandinya benar.
    $this->post('/masuk', ['email' => $user->email, 'password' => 'rahasia-123'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('hanya mengizinkan pengelola gudang dan petugas koordinasi masuk konsol', function (Role $role, int $status) {
    $this->actingAs(akun($role))->get('/logistik')->assertStatus($status);
})->with([
    [Role::WarehouseManager, 200],
    [Role::BpbdOperator, 200],
    [Role::SuperAdmin, 200],
    [Role::CampCoordinator, 403],
    [Role::FieldVolunteer, 403],
]);

it('membatasi halaman jejak audit untuk yang berhak melihat audit', function () {
    $this->actingAs(akun(Role::WarehouseManager))->get('/logistik/audit')->assertForbidden();

    // Sesi lama dibuang dulu: sesi yang terikat ke kata sandi satu pengguna sengaja terputus bila pengguna lain memakainya.
    $this->flushSession();
    $this->actingAs(akun(Role::BpbdOperator, 'rahasia-456'))->get('/logistik/audit')->assertOk();
});

it('membagikan peran dan hak pengguna ke halaman', function () {
    $this->actingAs(akun(Role::WarehouseManager))->get('/logistik')
        ->assertInertia(fn (Assert $p) => $p
            ->component('Logistik/Permintaan')
            ->where('auth.user.roles', ['warehouse-manager'])
            // Nama hak mengandung titik, jadi dibandingkan lewat closure, bukan jalur "a.b.c".
            ->where('auth.user.can', fn ($can) => ($can['dispatch.create'] ?? null) === true && ($can['audit.view'] ?? null) === false));
});

it('keluar menghapus sesi', function () {
    $this->actingAs(akun(Role::WarehouseManager))->post('/keluar')->assertRedirect('/masuk');
    $this->assertGuest();
});

it('tetap bisa dipakai API dengan cookie sesi dari konsol (tanpa token)', function () {
    $user = akun(Role::WarehouseManager);

    // Permintaan "dari peramban": asal yang dikenal sebagai frontend stateful.
    $this->actingAs($user)->withHeader('Origin', 'http://localhost:8000')->withHeader('Referer', 'http://localhost:8000/logistik')
        ->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $user->id);
});
