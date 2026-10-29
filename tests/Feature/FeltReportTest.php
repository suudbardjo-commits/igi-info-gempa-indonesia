<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('laporanwarga');
});

it('menyimpan laporan warga dengan koordinat dibulatkan 2 desimal tanpa identitas', function () {
    $this->postJson('/api/v1/felt-reports', ['level' => 'strong', 'lat' => -6.123456, 'lng' => 106.987654, 'quake_key' => 'bmkg:abc'])
        ->assertCreated()->assertJsonPath('status', 'ok');

    $row = DB::table('felt_reports')->first();
    expect($row->level)->toBe('strong')
        ->and((float) $row->latitude)->toBe(-6.12)
        ->and((float) $row->longitude)->toBe(106.99)
        ->and($row->quake_key)->toBe('bmkg:abc');
    expect(array_keys((array) $row))->not->toContain('ip', 'user_id', 'user_agent');
});

it('menolak tingkat tidak dikenal dan koordinat di luar Indonesia', function () {
    $this->postJson('/api/v1/felt-reports', ['level' => 'bahaya', 'lat' => -6, 'lng' => 106])->assertStatus(422);
    $this->postJson('/api/v1/felt-reports', ['level' => 'light', 'lat' => 48.8, 'lng' => 2.3])->assertStatus(422);
    $this->postJson('/api/v1/felt-reports', ['level' => 'light'])->assertStatus(422);
    expect(DB::table('felt_reports')->count())->toBe(0);
});

it('mengganti waktu kejadian yang di masa depan atau lebih dari 24 jam dengan waktu server', function () {
    $this->postJson('/api/v1/felt-reports', ['level' => 'light', 'lat' => -6, 'lng' => 106, 'felt_at' => now()->addDays(3)->toIso8601String()])->assertCreated();
    $this->postJson('/api/v1/felt-reports', ['level' => 'light', 'lat' => -6, 'lng' => 106, 'felt_at' => now()->subDays(9)->toIso8601String()])->assertCreated();

    foreach (DB::table('felt_reports')->get() as $r) {
        expect(abs(now()->diffInSeconds($r->felt_at)))->toBeLessThan(30);
    }
});

it('membatasi pengiriman 6 laporan per 10 menit', function () {
    foreach (range(1, 6) as $i) {
        $this->postJson('/api/v1/felt-reports', ['level' => 'none', 'lat' => -6, 'lng' => 106])->assertCreated();
    }
    $this->postJson('/api/v1/felt-reports', ['level' => 'none', 'lat' => -6, 'lng' => 106])->assertStatus(429);
});

it('menampilkan laporan terbaru sebagai titik yang ditandai belum diverifikasi', function () {
    DB::table('felt_reports')->insert([
        ['level' => 'strong', 'latitude' => -6.2, 'longitude' => 106.8, 'quake_key' => null, 'felt_at' => now(), 'created_at' => now()],
        ['level' => 'light', 'latitude' => -7.0, 'longitude' => 110.0, 'quake_key' => null, 'felt_at' => now()->subDays(3), 'created_at' => now()->subDays(3)],
    ]);

    $this->getJson('/api/v1/felt-reports?hours=6')
        ->assertOk()->assertJsonPath('verified', false)->assertJsonPath('count', 1)
        ->assertJsonPath('reports.0.level', 'strong')->assertJsonPath('reports.0.lat', -6.2);
});
