<?php

use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use Illuminate\Support\Facades\DB;

function makeCamp(string $code, float $lng, float $lat): EvacuationCamp
{
    return EvacuationCamp::create([
        'name' => $code,
        'code' => $code,
        'coordinates' => EvacuationCamp::makePoint($lng, $lat),
        'capacity_people' => 100,
        'contact_phone' => '0810000000',
    ]);
}

it('mengaktifkan ekstensi PostGIS', function () {
    expect(DB::selectOne('SELECT PostGIS_Version() AS v')->v)->not->toBeEmpty();
});

it('menemukan posko dalam radius dan mengurutkannya dari yang terdekat', function () {
    // Pusat gempa: dekat Lumajang.
    $lng = 113.2246;
    $lat = -8.1335;

    makeCamp('DEKAT', 113.2300, -8.1400);      // sekitar 1 km
    makeCamp('SEDANG', 113.4000, -8.1500);     // sekitar 20 km
    makeCamp('JAUH', 114.3691, -8.2192);       // Banyuwangi, > 100 km

    $camps = EvacuationCamp::query()
        ->withinRadius($lng, $lat, 50)
        ->withDistanceFrom($lng, $lat)
        ->orderBy('distance_km')
        ->get();

    expect($camps->pluck('code')->all())->toBe(['DEKAT', 'SEDANG'])
        ->and($camps->first()->distance_km)->toBeLessThan(2.0);
});

it('mengembalikan longitude dan latitude lewat scope withCoordinates', function () {
    makeCamp('TITIK', 112.7521, -7.2575);

    $camp = EvacuationCamp::query()->withCoordinates()->where('code', 'TITIK')->firstOrFail();

    expect((float) $camp->longitude)->toEqualWithDelta(112.7521, 0.0001)
        ->and((float) $camp->latitude)->toEqualWithDelta(-7.2575, 0.0001);
});

it('menyimpan kejadian bencana dengan casting enum', function () {
    $event = DisasterEvent::create([
        'external_id' => 'TEST-1',
        'source' => 'bmkg',
        'type' => 'earthquake',
        'magnitude' => 5.2,
        'location_name' => 'Uji',
        'coordinates' => DisasterEvent::makePoint(102.14, -3.11),
        'occurred_at' => now(),
    ]);

    expect($event->fresh()->source->value)->toBe('bmkg');
});

it('menolak stok negatif lewat constraint database', function () {
    $camp = makeCamp('STOK', 112.0, -7.0);

    expect(fn () => DB::table('evacuation_camps')->where('id', $camp->id)->update(['current_occupants' => -1]))
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('membuat tabel audit bersifat append-only', function () {
    $id = DB::table('logistics_audit_logs')->insertGetId([
        'action' => 'TEST',
        'entity_type' => 'x',
        'entity_id' => (string) Illuminate\Support\Str::uuid(),
        'hash' => str_repeat('a', 64),
    ]);

    expect(fn () => DB::table('logistics_audit_logs')->where('id', $id)->update(['action' => 'UBAH']))
        ->toThrow(Illuminate\Database\QueryException::class)
        ->and(fn () => DB::table('logistics_audit_logs')->where('id', $id)->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});
