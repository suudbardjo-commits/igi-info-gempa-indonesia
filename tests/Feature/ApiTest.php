<?php

use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;

function quake(string $id, float $mag, float $lng, float $lat, array $extra = []): DisasterEvent
{
    return DisasterEvent::create(array_merge([
        'external_id' => $id,
        'source' => 'bmkg',
        'type' => 'earthquake',
        'magnitude' => $mag,
        'location_name' => "Lokasi {$id}",
        'impact_radius_km' => 35,
        'is_verified' => true,
        'occurred_at' => now()->subHour(),
        'coordinates' => DisasterEvent::makePoint($lng, $lat),
    ], $extra));
}

it('mengembalikan GeoJSON FeatureCollection dengan urutan [lng, lat]', function () {
    quake('A', 5.2, 102.14, -3.11);

    $res = $this->getJson('/api/v1/disasters')->assertOk();

    $res->assertJsonPath('type', 'FeatureCollection')
        ->assertJsonPath('features.0.type', 'Feature')
        ->assertJsonPath('features.0.geometry.type', 'Point')
        ->assertJsonPath('features.0.properties.magnitude', 5.2)
        ->assertJsonPath('features.0.properties.source', 'bmkg');

    expect($res->json('features.0.geometry.coordinates'))->toEqualWithDelta([102.14, -3.11], 0.0001);
});

it('menyembunyikan laporan duplikat dari sumber lain', function () {
    $main = quake('MAIN', 5.2, 102.14, -3.11);
    quake('DUP', 5.0, 102.2, -3.0, ['source' => 'usgs', 'canonical_event_id' => $main->id]);

    $this->getJson('/api/v1/disasters')->assertOk()->assertJsonCount(1, 'features');
});

it('menyaring berdasarkan magnitudo minimum dan rentang jam', function () {
    quake('KECIL', 4.1, 110, -7);
    quake('BESAR', 6.3, 111, -7);
    quake('LAMA', 6.9, 112, -7, ['occurred_at' => now()->subDays(10)]);

    $this->getJson('/api/v1/disasters?min_magnitude=5')->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.properties.magnitude', 6.3);

    // Default 168 jam (7 hari): gempa 10 hari lalu tidak muncul; dengan hours=480 muncul.
    $this->getJson('/api/v1/disasters')->assertJsonCount(2, 'features');
    $this->getJson('/api/v1/disasters?hours=480')->assertJsonCount(3, 'features');
});

it('mengurutkan dari yang terbaru dan menghormati limit', function () {
    quake('LAMA', 5, 110, -7, ['occurred_at' => now()->subHours(5)]);
    quake('BARU', 5, 111, -7, ['occurred_at' => now()->subMinutes(5)]);

    $this->getJson('/api/v1/disasters?limit=1')->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.properties.location', 'Lokasi BARU');
});

it('menolak parameter yang tidak valid', function () {
    $this->getJson('/api/v1/disasters?min_magnitude=abc')->assertUnprocessable();
    $this->getJson('/api/v1/disasters?limit=9999')->assertUnprocessable();
    $this->getJson('/api/v1/disasters?source=nasa')->assertUnprocessable();
});

it('menampilkan rincian kejadian beserta posko terdampak urut jarak', function () {
    $event = quake('DETAIL', 6.1, 113.0, -8.0, ['impact_radius_km' => 60]);

    foreach ([['JAUH', 113.3, -8.0], ['DEKAT', 113.05, -8.0], ['LUAR', 116.0, -8.0]] as [$code, $lng, $lat]) {
        EvacuationCamp::create([
            'name' => $code, 'code' => $code, 'capacity_people' => 100, 'contact_phone' => '0811111',
            'coordinates' => EvacuationCamp::makePoint($lng, $lat),
        ]);
    }

    $res = $this->getJson("/api/v1/disasters/{$event->id}")->assertOk();

    expect(collect($res->json('affected_camps'))->pluck('code')->all())->toBe(['DEKAT', 'JAUH']);
    $res->assertJsonPath('properties.impact_radius_km', 60);
});

it('mengembalikan 404 untuk kejadian yang tidak ada', function () {
    $this->getJson('/api/v1/disasters/'.Illuminate\Support\Str::uuid())->assertNotFound();
});

it('daftar posko tidak membocorkan nomor telepon dan menyembunyikan posko tutup', function () {
    foreach ([['BUKA', 'active'], ['TUTUP', 'closed']] as [$code, $status]) {
        EvacuationCamp::create([
            'name' => $code, 'code' => $code, 'status' => $status, 'capacity_people' => 100,
            'contact_phone' => '081234567890', 'coordinates' => EvacuationCamp::makePoint(112, -7),
        ]);
    }

    $res = $this->getJson('/api/v1/camps')->assertOk()->assertJsonCount(1, 'features');

    expect($res->getContent())->not->toContain('081234567890')->not->toContain('contact_phone');
});
