<?php

use App\Events\EarthquakeDetectedEvent;
use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;
use App\Services\DisasterIngestion\BmkgClient;
use App\Services\GeoSpatial\ImpactAssessmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/** Waktu gempa dalam tes: 10 menit lalu, supaya dianggap segar oleh aturan alert. */
function quakeAt(): Carbon\CarbonImmutable
{
    static $at = null;

    return $at ??= now()->subMinutes(10)->toImmutable()->setMicroseconds(0);
}

function bmkgPayload(array $overrides = []): array
{
    return ['Infogempa' => ['gempa' => [array_merge([
        'Tanggal' => '22 Okt 2026',
        'Jam' => '17:57:49 WIB',
        'DateTime' => quakeAt()->utc()->format('Y-m-d\TH:i:sP'),
        'Coordinates' => '-3.11,102.14',
        'Lintang' => '3.11 LS',
        'Bujur' => '102.14 BT',
        'Magnitude' => '5.2',
        'Kedalaman' => '120 km',
        'Wilayah' => '6 km BaratLaut LEBONG-BENGKULU',
        'Potensi' => 'Tidak berpotensi tsunami',
    ], $overrides)]]];
}

function usgsPayload(array $props = [], array $coords = [102.2, -3.0, 118.0]): array
{
    return ['features' => [[
        'id' => 'us7000test',
        'properties' => array_merge([
            'mag' => 5.4,
            'place' => '10 km W of Lebong, Indonesia',
            // 6 detik setelah BMKG.
            'time' => quakeAt()->addSeconds(6)->getTimestampMs(),
            'tsunami' => 0,
        ], $props),
        'geometry' => ['type' => 'Point', 'coordinates' => $coords],
    ]]];
}

it('menormalkan data BMKG: lat/lng, waktu ISO, magnitudo dan kedalaman', function () {
    $item = bmkgPayload()['Infogempa']['gempa'][0];

    $row = app(BmkgClient::class)->normalize($item);

    expect($row)->toMatchArray([
        'source' => 'bmkg',
        'magnitude' => 5.2,
        'depth_km' => 120.0,
        'lat' => -3.11,
        'lng' => 102.14,
    ])->and($row['occurred_at']->toIso8601String())->toBe(quakeAt()->utc()->toIso8601String());
});

it('membuang data BMKG dengan koordinat rusak', function () {
    expect(app(BmkgClient::class)->normalize(bmkgPayload(['Coordinates' => 'abc'])['Infogempa']['gempa'][0]))->toBeNull();
});

it('menyimpan gempa BMKG sekali saja walau command dijalankan berulang', function () {
    Event::fake([EarthquakeDetectedEvent::class]);
    Http::fake(['data.bmkg.go.id/*' => Http::response(bmkgPayload(), 200, ['ETag' => '"abc"'])]);

    $this->artisan('ingest:bmkg')->assertSuccessful();
    $this->artisan('ingest:bmkg --feed=auto')->assertSuccessful();

    expect(DisasterEvent::count())->toBe(1);
    Event::assertDispatchedTimes(EarthquakeDetectedEvent::class, 1);

    $event = DisasterEvent::first();
    expect($event->impact_radius_km)->toBe(35)   // M5.2
        ->and($event->is_verified)->toBeTrue();
});

it('memakai ETag dan melewati data yang tidak berubah (304)', function () {
    Http::fakeSequence('data.bmkg.go.id/*')
        ->push(bmkgPayload(), 200, ['ETag' => '"v1"'])
        ->push('', 304);

    $this->artisan('ingest:bmkg')->assertSuccessful();
    $this->artisan('ingest:bmkg')->expectsOutputToContain('Tidak ada data baru')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1"'));
});

it('mengirim Accept */* ke BMKG karena firewall-nya memblokir application/json', function () {
    Http::fake(['data.bmkg.go.id/*' => Http::response(bmkgPayload())]);

    $this->artisan('ingest:bmkg')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->hasHeader('Accept', '*/*'));
});

it('melaporkan kegagalan BMKG sebagai error, bukan "tidak ada data baru"', function () {
    Http::fake(['data.bmkg.go.id/*' => Http::response('Laman diblokir', 403)]);

    $this->artisan('ingest:bmkg')
        ->expectsOutputToContain('HTTP 403')
        ->assertFailed();

    expect(DisasterEvent::count())->toBe(0);
});

it('menandai gempa USGS yang sama dengan BMKG sebagai duplikat dan tidak memicu alert kedua', function () {
    Event::fake([EarthquakeDetectedEvent::class]);

    Http::fake([
        'data.bmkg.go.id/*' => Http::response(bmkgPayload()),
        'earthquake.usgs.gov/*' => Http::response(usgsPayload()),
    ]);

    $this->artisan('ingest:bmkg')->assertSuccessful();
    $this->artisan('ingest:usgs')->assertSuccessful();

    expect(DisasterEvent::count())->toBe(2);

    $bmkg = DisasterEvent::where('source', 'bmkg')->firstOrFail();
    $usgs = DisasterEvent::where('source', 'usgs')->firstOrFail();

    expect($usgs->canonical_event_id)->toBe($bmkg->id)
        ->and($bmkg->canonical_event_id)->toBeNull();

    Event::assertDispatchedTimes(EarthquakeDetectedEvent::class, 1);
});

it('menjadikan BMKG kejadian utama walau laporan USGS masuk lebih dulu', function () {
    Event::fake([EarthquakeDetectedEvent::class]);

    Http::fake([
        'data.bmkg.go.id/*' => Http::response(bmkgPayload()),
        'earthquake.usgs.gov/*' => Http::response(usgsPayload()),
    ]);

    $this->artisan('ingest:usgs')->assertSuccessful();
    $this->artisan('ingest:bmkg')->assertSuccessful();

    $bmkg = DisasterEvent::where('source', 'bmkg')->firstOrFail();
    $usgs = DisasterEvent::where('source', 'usgs')->firstOrFail();

    expect($bmkg->canonical_event_id)->toBeNull()
        ->and($usgs->canonical_event_id)->toBe($bmkg->id);

    // Peringatan hanya sekali, dari laporan pertama yang masuk (USGS).
    Event::assertDispatchedTimes(EarthquakeDetectedEvent::class, 1);

    // Tampilan publik menampilkan versi BMKG.
    $this->getJson('/api/v1/disasters')->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.properties.source', 'bmkg');
});

it('tidak menggabungkan gempa yang jauh berbeda waktu atau lokasi', function () {
    Event::fake([EarthquakeDetectedEvent::class]);

    Http::fake([
        'data.bmkg.go.id/*' => Http::response(bmkgPayload()),
        // Lokasi sama, tapi 20 menit kemudian.
        'earthquake.usgs.gov/*' => Http::response(usgsPayload(['time' => quakeAt()->addMinutes(20)->getTimestampMs()])),
    ]);

    $this->artisan('ingest:bmkg');
    $this->artisan('ingest:usgs');

    expect(DisasterEvent::whereNotNull('canonical_event_id')->count())->toBe(0);
    Event::assertDispatchedTimes(EarthquakeDetectedEvent::class, 2);
});

it('mengirim koordinat di payload broadcast', function () {
    Event::fake([EarthquakeDetectedEvent::class]);
    Http::fake(['data.bmkg.go.id/*' => Http::response(bmkgPayload())]);

    $this->artisan('ingest:bmkg');

    Event::assertDispatched(EarthquakeDetectedEvent::class, function ($e) {
        $payload = $e->broadcastWith();

        return $payload['latitude'] === -3.11
            && $payload['longitude'] === 102.14
            && $e->broadcastAs() === 'earthquake.detected';
    });
});

it('menyimpan gempa lama tanpa siaran atau alert, tetapi tetap menilai dampaknya', function () {
    Event::fake([EarthquakeDetectedEvent::class]);

    EvacuationCamp::create([
        'name' => 'NEAR', 'code' => 'NEAR', 'capacity_people' => 100, 'contact_phone' => '0',
        'coordinates' => EvacuationCamp::makePoint(102.15, -3.12),
    ]);

    // Gempa 2 hari lalu: ikut terambil karena feed berisi beberapa hari.
    $old = quakeAt()->subDays(2)->utc()->format('Y-m-d\TH:i:sP');
    Http::fake(['data.bmkg.go.id/*' => Http::response(bmkgPayload(['DateTime' => $old]))]);

    $this->artisan('ingest:bmkg')->assertSuccessful();

    expect(DisasterEvent::count())->toBe(1);
    Event::assertNotDispatched(EarthquakeDetectedEvent::class);
    // Penilaian dampak berjalan (antrean sync pada tes), sehingga pivot terisi.
    expect(DB::table('disaster_event_camp')->count())->toBe(1);
});

it('menyiarkan gempa yang masih segar, dan memakai batas usia yang bisa diatur', function () {
    Event::fake([EarthquakeDetectedEvent::class]);
    Http::fake(['data.bmkg.go.id/*' => Http::response(bmkgPayload())]);

    // Gempa 10 menit lalu; dengan batas 5 menit sudah dianggap basi.
    config(['disaster.alerts.max_age_minutes' => 5]);
    $this->artisan('ingest:bmkg');
    Event::assertNotDispatched(EarthquakeDetectedEvent::class);

    DisasterEvent::query()->delete();
    config(['disaster.alerts.max_age_minutes' => 30]);
    $this->artisan('ingest:bmkg');
    Event::assertDispatchedTimes(EarthquakeDetectedEvent::class, 1);
});

it('menyimpan posko terdampak sebagai hasil penilaian dampak', function () {
    Http::fake(['data.bmkg.go.id/*' => Http::response(bmkgPayload())]);

    foreach ([['NEAR', 102.15, -3.12], ['FAR', 105.0, -6.0]] as [$code, $lng, $lat]) {
        EvacuationCamp::create([
            'name' => $code, 'code' => $code, 'capacity_people' => 100, 'contact_phone' => '0',
            'coordinates' => EvacuationCamp::makePoint($lng, $lat),
        ]);
    }

    // Queue 'sync' pada tes, jadi listener dan job langsung berjalan.
    $this->artisan('ingest:bmkg')->assertSuccessful();

    $affected = DB::table('disaster_event_camp')->join('evacuation_camps', 'camp_id', '=', 'evacuation_camps.id')->pluck('code');

    expect($affected->all())->toBe(['NEAR']);
});

it('ImpactAssessmentService mengurutkan posko dari yang terdekat', function () {
    $event = DisasterEvent::create([
        'external_id' => 'T-1', 'source' => 'manual', 'type' => 'earthquake', 'magnitude' => 6.5,
        'location_name' => 'Uji', 'impact_radius_km' => 60, 'occurred_at' => now(),
        'coordinates' => DisasterEvent::makePoint(113.0, -8.0),
    ]);

    foreach ([['B', 113.3, -8.0], ['A', 113.05, -8.0]] as [$code, $lng, $lat]) {
        EvacuationCamp::create([
            'name' => $code, 'code' => $code, 'capacity_people' => 100, 'contact_phone' => '0',
            'coordinates' => EvacuationCamp::makePoint($lng, $lat),
        ]);
    }

    $camps = app(ImpactAssessmentService::class)->affectedCamps($event);

    expect($camps->pluck('code')->all())->toBe(['A', 'B']);
});
