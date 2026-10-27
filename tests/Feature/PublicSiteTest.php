<?php

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

function operator(): User
{
    return User::factory()->create()->assignRole(Role::BpbdOperator->value);
}

it('menampilkan beranda dengan angka ringkas, gempa terbaru, dan pengumuman yang berlaku', function () {
    lgQuake(5.4, 113.2, -8.1);
    lgQuake(3.1, 113.4, -8.2, ['occurred_at' => now()->subDays(3)]);
    lgCamp('PSK-A', 113.2, -8.1)->update(['current_occupants' => 80]);
    Announcement::create(['title' => 'Jalur utama ditutup', 'body' => 'Gunakan jalur alternatif.', 'level' => 'penting']);
    Announcement::create(['title' => 'Sudah lewat', 'body' => 'Pengumuman lama sekali.', 'level' => 'info', 'expires_at' => now()->subHour()]);

    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Beranda')
        ->where('stats.quakes_24h', 1)
        ->where('stats.quakes_7d', 2)
        ->where('stats.strong_7d', 1)
        ->where('stats.camps_open', 1)
        ->where('stats.occupants', 80)
        ->has('latest', 2)
        ->has('announcements', 1)
        ->where('announcements.0.title', 'Jalur utama ditutup'));
});

it('beranda memuat seminggu penuh untuk seismogram dan peta sebaran, dan daftar enam teratas', function () {
    foreach (range(1, 8) as $i) {
        lgQuake(4.0 + $i / 10, 113.0 + $i / 10, -8.0, ['external_id' => "W{$i}", 'occurred_at' => now()->subHours($i)]);
    }
    lgQuake(5.0, 100.0, 1.0, ['external_id' => 'LAMA', 'occurred_at' => now()->subDays(9)]);
    lgCamp('PSK-Z', 113.2, -8.1);

    $this->get('/')->assertInertia(fn (Assert $p) => $p
        ->has('week', 8)
        ->has('latest', 6)
        ->has('camps', 1)
        ->where('week.0.id', fn ($id) => is_string($id))
        ->where('latest.0.id', fn ($id) => true));
});

it('memakai nama IGI di manifes PWA', function () {
    $this->getJson('/manifest.webmanifest')->assertOk()
        ->assertJsonPath('short_name', 'IGI')
        ->assertJsonPath('name', 'IGI - Info Gempa Indonesia')
        ->assertJsonPath('theme_color', '#0A1630');
});

it('beranda tetap tampil saat belum ada data sama sekali', function () {
    $this->get('/')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Beranda')->where('stats.quakes_24h', 0)->where('stats.strongest_7d', null)->has('latest', 0));
});

it('menyajikan peta di /peta', function () {
    $this->get('/peta')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Peta'));
});

it('daftar berita menyaring dan memberi halaman, tanpa laporan duplikat', function () {
    $main = lgQuake(5.5, 113.2, -8.1);
    lgQuake(4.9, 113.2, -8.1, ['source' => 'usgs', 'external_id' => 'DUP', 'canonical_event_id' => $main->id]);
    lgQuake(3.2, 112.0, -7.0);

    $this->get('/berita')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Berita/Index')->where('pagination.total', 2)->has('events', 2));

    $this->get('/berita?min_magnitude=5')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('pagination.total', 1)->where('filters.min_magnitude', 5));

    // Filter tak dikenal (tautan lama/salah ketik) diabaikan, bukan dialihkan.
    $this->get('/berita?hours=999&min_magnitude=abc&source=xyz')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('filters.hours', 168)->where('filters.min_magnitude', 0)->where('filters.source', null)->where('pagination.total', 2));

    // Satu filter sah tetap dipakai walau yang lain tidak sah.
    $this->get('/berita?min_magnitude=5&hours=999')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('filters.min_magnitude', 5)->where('filters.hours', 168)->where('pagination.total', 1));
});

it('halaman berita memuat posko terdampak dan gempa di sekitarnya', function () {
    $event = lgQuake(6.0, 113.2, -8.1);
    $camp = lgCamp('PSK-A', 113.21, -8.11);
    $near = lgQuake(4.5, 113.25, -8.12, ['external_id' => 'NEAR', 'occurred_at' => now()->subHours(2)]);
    lgQuake(4.5, 100.0, 1.0, ['external_id' => 'FAR']);

    $this->get("/berita/{$event->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Berita/Show')
        ->where('event.id', $event->id)
        ->has('affected_camps', 1)
        ->where('affected_camps.0.id', $camp->id)
        ->has('nearby', 1)
        ->where('nearby.0.id', $near->id));

    $this->get('/berita/bukan-uuid')->assertNotFound();
    $this->get('/berita/'.\Illuminate\Support\Str::uuid())->assertNotFound();
});

it('menampilkan panduan', function () {
    $this->get('/panduan')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Panduan'));
});

it('API statistik dan pengumuman terbuka untuk umum', function () {
    lgQuake(5.0, 113.2, -8.1);
    Announcement::create(['title' => 'Info posko baru', 'body' => 'Posko baru dibuka di balai desa.', 'level' => 'info']);

    $this->getJson('/api/v1/stats')->assertOk()->assertJsonPath('quakes_24h', 1);
    $this->getJson('/api/v1/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.level', 'info');
});

it('hanya operator yang boleh menerbitkan dan menarik pengumuman', function () {
    $payload = ['title' => 'Banjir susulan', 'body' => 'Waspadai banjir di dataran rendah.', 'level' => 'darurat', 'expires_in_hours' => 6];

    $this->postJson('/api/v1/announcements', $payload)->assertUnauthorized();

    $relawan = User::factory()->create()->assignRole(Role::FieldVolunteer->value);
    $this->actingAs($relawan)->postJson('/api/v1/announcements', $payload)->assertForbidden();

    $id = $this->actingAs(operator())->postJson('/api/v1/announcements', $payload)
        ->assertCreated()->assertJsonPath('level', 'darurat')->json('id');

    expect(Announcement::find($id)->expires_at->isFuture())->toBeTrue();

    $this->actingAs($relawan)->deleteJson("/api/v1/announcements/{$id}")->assertForbidden();
    $this->actingAs(operator())->deleteJson("/api/v1/announcements/{$id}")->assertNoContent();
    expect(Announcement::count())->toBe(0);
});

it('menolak pengumuman dengan tingkat atau isi tidak sah', function () {
    $op = operator();

    $this->actingAs($op)->postJson('/api/v1/announcements', ['title' => 'x', 'body' => 'pendek', 'level' => 'rahasia'])
        ->assertUnprocessable()->assertJsonValidationErrors(['title', 'body', 'level']);
});

it('daftar kelola menandai pengumuman kedaluwarsa, hanya untuk operator', function () {
    Announcement::create(['title' => 'Masih tayang', 'body' => 'Pengumuman yang masih berlaku.', 'level' => 'info']);
    Announcement::create(['title' => 'Sudah usai', 'body' => 'Pengumuman yang sudah usai.', 'level' => 'info', 'expires_at' => now()->subDay()]);

    $this->actingAs(User::factory()->create()->assignRole(Role::WarehouseManager->value))->getJson('/api/v1/announcements/manage')->assertForbidden();

    $res = $this->actingAs(operator())->getJson('/api/v1/announcements/manage')->assertOk();
    expect(collect($res->json('data'))->pluck('active', 'title')->all())->toBe(['Masih tayang' => true, 'Sudah usai' => false]);
});

it('halaman kelola pengumuman hanya untuk operator', function () {
    $this->get('/logistik/pengumuman')->assertRedirect('/masuk');
    $this->actingAs(User::factory()->create()->assignRole(Role::WarehouseManager->value))->get('/logistik/pengumuman')->assertForbidden();
    $this->actingAs(operator())->get('/logistik/pengumuman')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Logistik/Pengumuman'));
});
