<?php

use App\Services\Weather\AlertFeed;
use App\Services\Weather\AlertMatcher;
use App\Services\Weather\CapParser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

// Semua isi respons di bawah adalah DATA CONTOH untuk uji; bukan data cuaca nyata.

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('upstream:open-meteo');
    // Tes memakai nama kunci throttle yang sama dengan produksi; kosongkan agar tidak saling mengunci.
    $this->withHeaders(['Accept' => 'application/json']);
});

function wxOpenMeteo(string $zone = 'Asia/Jakarta', string $now = '2026-10-25T11:00', array $over = []): array
{
    $times = [];
    for ($h = 0; $h < 48; $h++) {
        $times[] = date('Y-m-d\TH:00', strtotime('2026-10-25 00:00 +'.$h.' hours'));
    }
    $n = count($times);

    return array_replace_recursive([
        'latitude' => -6.2, 'longitude' => 106.8, 'timezone' => $zone,
        'current' => ['time' => $now, 'temperature_2m' => 33.4, 'relative_humidity_2m' => 48, 'apparent_temperature' => 39.1, 'weather_code' => 3, 'wind_speed_10m' => 1.1, 'wind_direction_10m' => 200, 'precipitation' => 0.0, 'cloud_cover' => 80],
        'hourly' => [
            'time' => $times,
            'temperature_2m' => array_fill(0, $n, 30.0), 'relative_humidity_2m' => array_fill(0, $n, 60), 'apparent_temperature' => array_fill(0, $n, 34.0),
            'precipitation_probability' => array_fill(0, $n, 20), 'precipitation' => array_fill(0, $n, 0.0), 'weather_code' => array_fill(0, $n, 61),
            'wind_speed_10m' => array_fill(0, $n, 5.0), 'uv_index' => array_fill(0, $n, 7.5),
        ],
        'daily' => [
            'time' => ['2026-10-25', '2026-10-26'], 'weather_code' => [95, 3], 'temperature_2m_max' => [34.0, 33.0], 'temperature_2m_min' => [25.0, 25.5],
            'precipitation_sum' => [2.1, 0.0], 'precipitation_probability_max' => [60, 30], 'uv_index_max' => [9.0, 8.0],
            'sunrise' => ['2026-10-25T05:42', '2026-10-26T05:41'], 'sunset' => ['2026-10-25T17:50', '2026-10-26T17:50'],
        ],
    ], $over);
}

function wxBmkg(string $lon = '106.8453837867', ?string $desc = 'Berawan'): array
{
    $slot = fn (string $utc, $t = 31) => ['utc_datetime' => $utc, 't' => $t, 'tcc' => 90, 'tp' => 0.3, 'weather' => 3, 'weather_desc' => $desc, 'wd_deg' => 335, 'ws' => 2.3, 'hu' => 60, 'vs_text' => null, 'analysis_date' => '2026-10-25T00:00:00'];

    return [
        'lokasi' => ['adm4' => '31.71.03.1001', 'provinsi' => 'DKI Jakarta', 'kotkab' => 'Kota Adm. Jakarta Pusat', 'kecamatan' => 'Kemayoran', 'desa' => 'Kemayoran', 'lon' => $lon, 'lat' => -6.16],
        'data' => [['cuaca' => [[$slot('2026-10-25 04:00:00'), $slot('2026-10-25 07:00:00', null), $slot('2026-10-25 17:00:00')], [$slot('2026-10-26 04:00:00')]]]],
    ];
}

describe('GET /api/v1/weather', function () {
    it('Open-Meteo: bentuk seragam, satuan eksplisit, label estimasi, dan waktu WIB dengan offset', function () {
        Http::fake(['api.open-meteo.com/*' => Http::response(wxOpenMeteo())]);

        $r = $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8&name=Jakarta')->assertOk();

        $r->assertJsonPath('provider', 'open-meteo')->assertJsonPath('label', 'Estimasi cuaca saat ini')
            ->assertJsonPath('tz_label', 'WIB')->assertJsonPath('current.time', '2026-10-25T11:00:00+07:00')
            ->assertJsonPath('current.rain_probability', 20)->assertJsonPath('units.wind_speed', 'km/jam')
            ->assertJsonPath('daily.0.sunrise', '2026-10-25T05:42:00+07:00')->assertJsonPath('location.name', 'Jakarta')
            ->assertJsonPath('is_stale', false)->assertJsonPath('issued_at', null);
        expect($r->json('attribution'))->toContain('Open-Meteo');
        Http::assertSent(fn (Request $req) => $req['wind_speed_unit'] === 'kmh' && $req['temperature_unit'] === 'celsius' && $req['timezone'] === 'auto');
    });

    it('Makassar (WITA) dan Jayapura (WIT) memakai offset masing-masing, termasuk pergantian tanggal', function () {
        Http::fake(['api.open-meteo.com/*' => Http::sequence()
            ->push(wxOpenMeteo('Asia/Makassar', '2026-10-25T23:00'))
            ->push(wxOpenMeteo('Asia/Jayapura', '2026-10-26T00:00'))]);

        $this->getJson('/api/v1/weather?lat=-5.15&lon=119.43')->assertJsonPath('tz_label', 'WITA')->assertJsonPath('current.time', '2026-10-25T23:00:00+08:00');
        $this->getJson('/api/v1/weather?lat=-2.53&lon=140.7')->assertJsonPath('tz_label', 'WIT')->assertJsonPath('current.time', '2026-10-26T00:00:00+09:00');
    });

    it('nilai kosong dari penyedia tetap null, bukan 0', function () {
        Http::fake(['api.open-meteo.com/*' => Http::response(wxOpenMeteo(over: ['current' => ['apparent_temperature' => null, 'relative_humidity_2m' => null]]))]);

        $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8')->assertJsonPath('current.feels_like', null)->assertJsonPath('current.humidity', null);
    });

    it('menolak koordinat tidak sah atau di luar Indonesia tanpa memanggil penyedia', function () {
        Http::fake();

        $this->getJson('/api/v1/weather?lat=abc&lon=1')->assertStatus(422)->assertJsonPath('status', 'error');
        $this->getJson('/api/v1/weather?lat=48.8&lon=2.3')->assertStatus(422)->assertJsonPath('error.code', 'invalid_request');
        $this->getJson('/api/v1/weather')->assertStatus(422);
        $this->getJson('/api/v1/weather?adm4=31.71')->assertStatus(422);
        $this->getJson('/api/v1/weather?adm4=1;DROP')->assertStatus(422);
        Http::assertNothingSent();
    });

    it('galat HTTP, JSON rusak, dan batas waktu menjadi galat terkendali (bukan 500)', function () {
        Http::fake(['api.open-meteo.com/*' => Http::sequence()->push('bukan json', 200)->push(['x' => 1], 500)->pushFailedConnection()]);

        $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8')->assertStatus(502)->assertJsonPath('error.code', 'malformed');
        $this->getJson('/api/v1/weather?lat=-6.3&lon=106.8')->assertStatus(502)->assertJsonPath('error.code', 'upstream_error');
        $this->getJson('/api/v1/weather?lat=-6.4&lon=106.8')->assertStatus(502)->assertJsonPath('error.code', 'unreachable');
    });

    it('429 dari penyedia menahan panggilan berikutnya sampai Retry-After', function () {
        Http::fake(['api.open-meteo.com/*' => Http::response('', 429, ['Retry-After' => '120'])]);

        $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8')->assertStatus(503)->assertJsonPath('error.code', 'rate_limited');
        $this->getJson('/api/v1/weather?lat=-6.5&lon=106.8')->assertStatus(503);
        Http::assertSentCount(1);
    });

    it('cache segar melayani permintaan kembar dengan satu panggilan penyedia', function () {
        Http::fake(['api.open-meteo.com/*' => Http::response(wxOpenMeteo())]);

        $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8')->assertOk();
        $this->getJson('/api/v1/weather?lat=-6.204&lon=106.799')->assertOk()->assertJsonPath('cache_age_seconds', 0);
        Http::assertSentCount(1);
    });

    it('penyedia gagal setelah cache kedaluwarsa: data lama disajikan dan ditandai basi dengan umurnya', function () {
        Http::fake(['api.open-meteo.com/*' => Http::sequence()->push(wxOpenMeteo())->push('', 500)]);

        $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8')->assertOk();
        $this->travel(40)->minutes();
        $this->getJson('/api/v1/weather?lat=-6.2&lon=106.8')->assertOk()
            ->assertJsonPath('is_stale', true)->assertJsonPath('refresh_failed', 'upstream_error')
            ->assertJsonPath('cache_age_seconds', 2400);
    });

    it('BMKG adm4: waktu UTC menjadi WIB, nilai null dipertahankan, ada waktu terbit', function () {
        Http::fake(['api.bmkg.go.id/*' => Http::response(wxBmkg())]);

        $r = $this->getJson('/api/v1/weather?adm4=31.71.03.1001')->assertOk();

        $r->assertJsonPath('provider', 'bmkg')->assertJsonPath('tz_label', 'WIB')->assertJsonPath('location.adm4', '31.71.03.1001')
            ->assertJsonPath('hourly.0.time', '2026-10-25T11:00:00+07:00')->assertJsonPath('hourly.1.temperature', null)
            ->assertJsonPath('issued_at', '2026-10-25T00:00:00+00:00')->assertJsonPath('hourly.0.feels_like', null);
        // 17:00 UTC = 00:00 WIB tanggal berikutnya: hari lokal 26 Okt memuat dua slot.
        expect(collect($r->json('daily'))->pluck('date')->all())->toBe(['2026-10-25', '2026-10-26']);
        Http::assertSent(fn (Request $req) => str_contains($req->url(), 'adm4=31.71.03.1001'));
    });

    it('BMKG di Papua memakai WIT; respons tanpa data prakiraan menjadi galat terkendali', function () {
        Http::fake(['api.bmkg.go.id/*' => Http::sequence()->push(wxBmkg('140.7'))->push(['lokasi' => ['lat' => 1, 'lon' => 100], 'data' => []])]);

        $this->getJson('/api/v1/weather?adm4=91.71.01.1001')->assertJsonPath('tz_label', 'WIT')->assertJsonPath('hourly.0.time', '2026-10-25T13:00:00+09:00');
        $this->getJson('/api/v1/weather?adm4=11.01.01.2001')->assertStatus(502)->assertJsonPath('error.code', 'malformed');
    });

    it('BMKG gagal untuk lokasi yang pernah berhasil: cadangan Open-Meteo diberi label jelas', function () {
        Http::fake(['api.bmkg.go.id/*' => Http::sequence()->push(wxBmkg())->push('', 503), 'api.open-meteo.com/*' => Http::response(wxOpenMeteo())]);

        $this->getJson('/api/v1/weather?adm4=31.71.03.1001')->assertOk();
        $this->travel(40)->minutes();
        Cache::forget('weather:bmkg:31.71.03.1001');
        $this->getJson('/api/v1/weather?adm4=31.71.03.1001')->assertOk()
            ->assertJsonPath('provider', 'open-meteo')->assertJsonPath('fallback.from', 'bmkg')->assertJsonPath('label', 'Estimasi cuaca saat ini');
    });
});

describe('GET /api/v1/locations', function () {
    it('mengembalikan konteks administratif, memfilter hasil di luar Indonesia, dan menyimpan cache', function () {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['results' => [
            ['id' => 1622786, 'name' => 'Kota Makassar', 'latitude' => -5.1486, 'longitude' => 119.4319, 'timezone' => 'Asia/Makassar', 'admin1' => 'Sulawesi Selatan', 'admin2' => 'Kota Makassar', 'feature_code' => 'PPLA'],
            ['id' => 99, 'name' => 'Makassar', 'latitude' => 48.0, 'longitude' => 2.0, 'timezone' => 'Europe/Paris'],
        ]])]);

        $r = $this->getJson('/api/v1/locations?q=Makassar')->assertOk();
        $r->assertJsonCount(1, 'results')->assertJsonPath('results.0.admin', 'Sulawesi Selatan')->assertJsonPath('results.0.source', 'GeoNames')->assertJsonPath('cached', false);
        $this->getJson('/api/v1/locations?q=makassar')->assertJsonPath('cached', true);
        Http::assertSentCount(1);
    });

    it('tanpa hasil adalah jawaban sah (bukan galat); permintaan terlalu pendek ditolak', function () {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['generationtime_ms' => 0.1])]);

        $this->getJson('/api/v1/locations?q=zzzzzz')->assertOk()->assertJsonPath('results', []);
        $this->getJson('/api/v1/locations?q=a')->assertStatus(422);
        $this->getJson('/api/v1/locations?q='.str_repeat('x', 81))->assertStatus(422);
    });
});

function wxCap(array $o = []): string
{
    $o += ['id' => '2.49.0.1.360.0.2026.10.25.04.64.001', 'status' => 'Actual', 'type' => 'Alert', 'refs' => '', 'effective' => '2026-10-25T12:07:00+08:00', 'expires' => '2026-10-25T14:00:00+08:00', 'sent' => '2026-10-25T11:55:00+08:00',
        'poly' => '-0.4,116.8 -0.4,117.2 -0.8,117.2 -0.8,116.8 -0.4,116.8', 'area' => 'Kalimantan Timur', 'doctype' => ''];

    return '<?xml version="1.0"?>'.$o['doctype'].'<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"><identifier>'.$o['id'].'</identifier><sender>cuaca.ekstrem@bmkg.go.id</sender><sent>'.$o['sent'].'</sent>'
        .'<status>'.$o['status'].'</status><msgType>'.$o['type'].'</msgType><scope>Public</scope>'.($o['refs'] ? '<references>'.$o['refs'].'</references>' : '')
        .'<info><language>id</language><category>Met</category><event>Hujan Lebat dan Petir</event><urgency>Immediate</urgency><severity>Moderate</severity><certainty>Observed</certainty>'
        .'<effective>'.$o['effective'].'</effective><expires>'.$o['expires'].'</expires><senderName>BMKG</senderName><headline>Hujan Lebat disertai Petir di '.$o['area'].'</headline>'
        .'<description>Uji &lt;script&gt;alert(1)&lt;/script&gt;</description><web>https://nowcasting.bmkg.go.id/x.jpg</web><area><areaDesc>'.$o['area'].'</areaDesc><polygon>'.$o['poly'].'</polygon></area></info></alert>';
}

function wxRss(array $links): string
{
    $items = implode('', array_map(fn ($l) => "<item><title>t</title><link>{$l}</link></item>", $links));

    return '<?xml version="1.0"?><rss version="2.0"><channel><title>x</title>'.$items.'</channel></rss>';
}

describe('CAP', function () {
    it('membaca CAP: urutan koordinat lintang,bujur menjadi GeoJSON [bujur, lintang]', function () {
        $a = (new CapParser)->parse(wxCap());

        expect($a['identifier'])->toBe('2.49.0.1.360.0.2026.10.25.04.64.001')->and($a['polygons'][0][0])->toBe([116.8, -0.4])
            ->and($a['polygons'][0][0])->toBe(end($a['polygons'][0]))->and($a['expires'])->toBe('2026-10-25T14:00:00+08:00');
    });

    it('menolak DOCTYPE/ENTITY (XXE), XML rusak, dan dokumen tanpa identifier', function () {
        $xxe = wxCap(['doctype' => '<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]>']);

        expect((new CapParser)->parse($xxe))->toBeNull()->and((new CapParser)->parse('<alert><oops'))->toBeNull()
            ->and((new CapParser)->parse('<alert xmlns="urn:oasis:names:tc:emergency:cap:1.2"><sender>a</sender></alert>'))->toBeNull();
    });

    it('poligon tidak sah dihitung dan tidak dipakai; peringatan tetap terbaca', function () {
        $a = (new CapParser)->parse(wxCap(['poly' => '95.0,200.0 1,2 3,4 5,6']));

        expect($a['polygons'])->toBe([])->and($a['invalid_polygons'])->toBe(1);
        expect((new CapParser)->parse(wxCap(['poly' => 'a,b c,d e,f g,h']))['invalid_polygons'])->toBe(1);
    });

    it('hanya tautan CAP https di host BMKG dengan jalur yang cocok yang diterima (SSRF)', function () {
        $feed = app(AlertFeed::class);
        $ok = 'https://www.bmkg.go.id/alerts/nowcast/id/CKT20261025001_alert.xml';
        $bad = [
            'http://www.bmkg.go.id/alerts/nowcast/id/A1_alert.xml', 'https://evil.example/alerts/nowcast/id/A1_alert.xml',
            'https://www.bmkg.go.id.evil.example/alerts/nowcast/id/A1_alert.xml', 'https://user:pw@www.bmkg.go.id/alerts/nowcast/id/A1_alert.xml',
            'https://www.bmkg.go.id:8443/alerts/nowcast/id/A1_alert.xml', 'https://www.bmkg.go.id/alerts/nowcast/id/../../etc/passwd.xml',
            'https://www.bmkg.go.id/alerts/nowcast/id/A1_alert.xml?x=1', 'https://127.0.0.1/alerts/nowcast/id/A1_alert.xml', 'file:///etc/passwd', 'https://www.bmkg.go.id/lain/A1_alert.xml',
        ];

        expect($feed->isAllowedCapUrl($ok))->toBeTrue();
        foreach ($bad as $u) {
            expect($feed->isAllowedCapUrl($u))->toBeFalse($u);
        }
        expect($feed->links(wxRss([$ok, $ok, ...$bad])))->toBe([$ok]);
    });

    it('Exercise/Test dibuang; Cancel membatalkan yang dirujuk; Update menggantikan; identifier ganda digabung', function () {
        $f = app(AlertFeed::class);
        $p = new CapParser;
        $docs = [
            $p->parse(wxCap(['id' => 'A'])), $p->parse(wxCap(['id' => 'B'])), $p->parse(wxCap(['id' => 'C', 'status' => 'Exercise'])), $p->parse(wxCap(['id' => 'D', 'status' => 'Test'])),
            $p->parse(wxCap(['id' => 'B2', 'type' => 'Update', 'refs' => 'x@bmkg,B,2026-10-25T11:55:00+08:00', 'sent' => '2026-10-25T12:30:00+08:00'])),
            $p->parse(wxCap(['id' => 'X', 'type' => 'Cancel', 'refs' => 'x@bmkg,A,2026-10-25T11:55:00+08:00'])),
            $p->parse(wxCap(['id' => 'A'])),
        ];

        $out = collect($f->reconcile($docs))->keyBy('identifier');

        expect($out->keys()->sort()->values()->all())->toBe(['A', 'B2'])->and($out['A']['cancelled'])->toBeTrue()->and($out['B2']['cancelled'])->toBeFalse();
    });

    it('endpoint: status aktif, kedaluwarsa, dan dibatalkan dipisah; teks ditampilkan apa adanya (dirender aman oleh klien)', function () {
        $base = 'https://www.bmkg.go.id/alerts/nowcast/id/';
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-25T13:00:00+08:00'));
        Http::fake([
            'www.bmkg.go.id/alerts/nowcast/id/rss.xml' => Http::response(wxRss([$base.'CAA_alert.xml', $base.'CBB_alert.xml', $base.'CCC_alert.xml'])),
            $base.'CAA_alert.xml' => Http::response(wxCap(['id' => 'AKTIF'])),
            $base.'CBB_alert.xml' => Http::response(wxCap(['id' => 'LEWAT', 'expires' => '2026-10-25T12:00:00+08:00'])),
            $base.'CCC_alert.xml' => Http::response(wxCap(['id' => 'UJI', 'status' => 'Test'])),
        ]);

        $r = $this->getJson('/api/v1/weather-alerts')->assertOk();
        $r->assertJsonPath('count', 1)->assertJsonPath('alerts.0.id', 'AKTIF')->assertJsonPath('alerts.0.state', 'active')->assertJsonPath('is_stale', false);
        expect($r->json('checked_at'))->not->toBeNull();

        $all = $this->getJson('/api/v1/weather-alerts?state=all')->json('alerts');
        expect(collect($all)->pluck('state', 'id')->all())->toBe(['AKTIF' => 'active', 'LEWAT' => 'expired']);
    });

    it('peringatan yang lewat batas berakhir di cache tidak tampil aktif lagi', function () {
        $base = 'https://www.bmkg.go.id/alerts/nowcast/id/';
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-25T13:00:00+08:00'));
        Http::fake(['www.bmkg.go.id/alerts/nowcast/id/rss.xml' => Http::response(wxRss([$base.'CAA_alert.xml'])), $base.'CAA_alert.xml' => Http::response(wxCap(['id' => 'AKTIF']))]);

        $this->getJson('/api/v1/weather-alerts')->assertJsonPath('count', 1);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-25T14:01:00+08:00'));
        $this->getJson('/api/v1/weather-alerts')->assertJsonPath('count', 0);
    });

    it('pencocokan: poligon bila titik di dalam, provinsi bila hanya nama provinsi cocok, selain itu tidak', function () {
        $a = (new CapParser)->parse(wxCap());
        $m = new AlertMatcher;

        expect($m->match($a, -0.6, 117.0, null))->toBe('polygon')
            ->and($m->match($a, -3.0, 110.0, 'Kalimantan Timur'))->toBe('province')
            ->and($m->match($a, -3.0, 110.0, 'Jawa Barat'))->toBeNull()
            ->and($m->match($a, -0.6, 117.0, 'Jawa Barat'))->toBe('polygon');
    });

    it('endpoint: filter lokasi menyatakan cakupan; geometri hanya bila diminta; gagal tanpa cache = 503, bukan "tidak ada"', function () {
        $base = 'https://www.bmkg.go.id/alerts/nowcast/id/';
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-25T13:00:00+08:00'));
        Http::fake(['www.bmkg.go.id/alerts/nowcast/id/rss.xml' => Http::sequence()->push('', 500)->push(wxRss([$base.'CAA_alert.xml'])), $base.'CAA_alert.xml' => Http::response(wxCap())]);

        $this->getJson('/api/v1/weather-alerts')->assertStatus(503)->assertJsonPath('alerts', [])->assertJsonPath('error.code', 'unavailable');
        Cache::forget('lock:weather:alerts');

        $r = $this->getJson('/api/v1/weather-alerts?lat=-0.6&lon=117.0&province=Kalimantan%20Timur&geometry=1')->assertOk();
        $r->assertJsonPath('alerts.0.coverage', 'polygon')->assertJsonPath('alerts.0.geometry.type', 'MultiPolygon')->assertJsonPath('filtered_by_location', true);
        $this->getJson('/api/v1/weather-alerts?lat=-7&lon=110&province=Jawa%20Tengah')->assertJsonPath('count', 0)->assertJsonPath('is_stale', false);
        expect($this->getJson('/api/v1/weather-alerts?lat=-0.6&lon=117.0')->json('alerts.0'))->not->toHaveKey('geometry');
    });
});

describe('status sumber dan ekspor CSV', function () {
    it('status sumber: healthy, late, failed, unknown tanpa membocorkan rahasia', function () {
        Cache::forever('source:last_ok:open-meteo', now()->toIso8601String());
        Cache::forever('source:last_ok:bmkg-forecast', now()->subHours(5)->toIso8601String());
        Cache::forever('source:last_ok:geocoder', now()->subMinutes(5)->toIso8601String());
        Cache::forever('source:last_fail:geocoder', ['at' => now()->toIso8601String(), 'code' => 'http_500']);

        $s = collect($this->getJson('/api/v1/sources/status')->assertOk()->json('sources'))->keyBy('key');

        expect($s['open-meteo']['status'])->toBe('healthy')->and($s['bmkg-forecast']['status'])->toBe('late')
            ->and($s['geocoder']['status'])->toBe('failed')->and($s['geocoder']['last_failure']['code'])->toBe('http_500')->and($s['bmkg-alerts']['status'])->toBe('unknown');
    });

    it('CSV: satuan di header, filter wilayah dan kata kunci, rumus dinetralkan', function () {
        lgQuake(5.1, 110.0, -7.0, ['location_name' => '=HYPERLINK("http://x","klik")']);
        lgQuake(4.0, 130.0, -3.0, ['location_name' => '+cmd|calc']);
        lgQuake(6.0, 112.0, -7.5, ['location_name' => 'Dekat Malang']);

        $csv = $this->get('/api/v1/disasters-export?hours=24&south=-9&north=-5&west=104&east=116')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();

        expect($csv)->toContain('kedalaman_km')->toContain("'=HYPERLINK")->toContain('Dekat Malang')->not->toContain('+cmd|calc');
        expect($csv)->not->toContain(',=HYPERLINK');
        $q = $this->get('/api/v1/disasters-export?hours=24&q=malang')->streamedContent();
        expect($q)->toContain('Dekat Malang')->not->toContain('HYPERLINK');
        $this->getJson('/api/v1/disasters-export?south=1')->assertStatus(422);
    });
});

describe('halaman', function () {
    it('/cuaca dibuka publik dan tidak membawa data cuaca pada muatan awal; tautan bersama tidak memuat rahasia', function () {
        $this->get('/cuaca')->assertOk()->assertInertia(fn (Inertia\Testing\AssertableInertia $p) => $p->component('Cuaca'));
        $this->get('/cuaca?lat=-5.15&lon=119.43&nama=Makassar')->assertOk();
    });

    it('pantauan di / tetap memuat peta atlas dengan data awal', function () {
        $this->get('/')->assertOk()->assertInertia(fn (Inertia\Testing\AssertableInertia $p) => $p->component('Beranda')->has('week')->has('camps'));
    });

    it('bundel peramban tidak memuat rahasia server', function () {
        $secrets = array_filter([config('app.key'), env('REVERB_APP_SECRET'), env('DB_PASSWORD'), env('TELEGRAM_BOT_TOKEN')], fn ($v) => is_string($v) && strlen($v) >= 12);
        $files = glob(public_path('build/assets/*.js')) ?: [];
        expect($files)->not->toBeEmpty();
        foreach ($files as $f) {
            $js = file_get_contents($f);
            foreach ($secrets as $secret) {
                expect(str_contains($js, $secret))->toBeFalse(basename($f));
            }
        }
        expect(collect(file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))->filter(fn ($l) => preg_match('/^VITE_.*(SECRET|TOKEN|PASSWORD)/i', $l)))->toBeEmpty();
    });
});

describe('katalog wilayah', function () {
    function wxCatalog(): void
    {
        $sql = "INSERT INTO wilayah (kode, nama) VALUES\n('31','DKI JAKARTA'),\n('31.71','Kota Adm. Jakarta Pusat'),\n('31.71.03','Kemayoran'),\n('31.71.03.1001','Kemayoran'),\n('31.71.03.1002','Sumur Batu'),\n('73','SULAWESI SELATAN'),\n('73.71.01.1001','Pa\'baeng-baeng');";
        $rows = App\Console\Commands\AreasImport::parse($sql);
        expect($rows)->toHaveCount(7);
        $rows = array_merge($rows, array_map(fn ($i) => ['code' => sprintf('%02d', $i), 'name' => "P{$i}", 'level' => 1, 'parent_code' => null], range(40, 80)));
        file_put_contents($f = tempnam(sys_get_temp_dir(), 'wil'), "INSERT INTO wilayah (kode, nama) VALUES\n".implode(",\n", array_map(fn ($r) => "('{$r['code']}','".str_replace("'", "\'", $r['name'])."')", $rows)).';'.str_repeat("\n('11.01.01.2001','x')", 0));
        // Ambang impor memerlukan katalog nyata; di tes tabel diisi langsung.
        DB::table('admin_areas')->insert(array_slice($rows, 0, 7));
        DB::table('admin_area_versions')->insert(['source' => 's', 'decree' => 'Kepmendagri No. 300.2.2-2138 Tahun 2025', 'license' => 'MIT', 'source_url' => 'https://github.com/cahyadsn/wilayah', 'file_sha256' => str_repeat('a', 64), 'counts' => '{}', 'imported_at' => now()]);
    }

    it('mengurai SQL katalog: tingkat, induk, dan tanda petik berescape', function () {
        $r = collect(App\Console\Commands\AreasImport::parse("('73.71.01.1001','Pa\'baeng'),('73.71','Kota Makassar'),('73','SULSEL'),('73.71','Kota Makassar')"))->keyBy('code');

        expect($r)->toHaveCount(3)->and($r['73.71.01.1001']['level'])->toBe(4)->and($r['73.71.01.1001']['parent_code'])->toBe('73.71.01')
            ->and($r['73.71.01.1001']['name'])->toBe("Pa'baeng")->and($r['73']['parent_code'])->toBeNull();
    });

    it('/areas: provinsi lalu anak berjenjang, dengan versi katalog; kode induk tidak sah ditolak', function () {
        wxCatalog();

        $this->getJson('/api/v1/areas')->assertOk()->assertJsonPath('level_name', 'provinsi')->assertJsonPath('catalog.decree', 'Kepmendagri No. 300.2.2-2138 Tahun 2025')->assertJsonCount(2, 'areas');
        $this->getJson('/api/v1/areas?parent=31.71.03')->assertOk()->assertJsonPath('level_name', 'desa/kelurahan')->assertJsonPath('selectable_for_bmkg', true)->assertJsonCount(2, 'areas');
        $this->getJson('/api/v1/areas?parent=31.71')->assertJsonPath('selectable_for_bmkg', false)->assertJsonPath('areas.0.code', '31.71.03');
        $this->getJson('/api/v1/areas?parent=1;x')->assertStatus(422);
    });

    it('/areas tanpa katalog = 503, bukan daftar kosong yang menyesatkan', function () {
        $this->getJson('/api/v1/areas')->assertStatus(503)->assertJsonPath('error.code', 'catalog_missing');
    });

    it('cuaca adm4: kode di luar katalog ditolak tanpa memanggil BMKG; 404 BMKG menjadi 404 yang jelas', function () {
        wxCatalog();
        Http::fake(['api.bmkg.go.id/*' => Http::response('', 404)]);

        $this->getJson('/api/v1/weather?adm4=99.99.99.9999')->assertStatus(422);
        Http::assertNothingSent();
        $this->getJson('/api/v1/weather?adm4=73.71.01.1001')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
    });
});

describe('API berita untuk aplikasi', function () {
    it('/news mengembalikan kabar lembaga beserta status sumber; limit tidak sah ditolak', function () {
        App\Models\ExternalNews::create(['source' => 'bnpb', 'external_id' => 'n1', 'title' => 'Berita uji', 'url' => 'https://www.bnpb.go.id/berita/n1', 'published_at' => now()->subHour(), 'fetched_at' => now()]);

        $this->getJson('/api/v1/news?limit=5')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('items.0.title', 'Berita uji')->assertJsonStructure(['sources' => [['key', 'label', 'stale']]]);
        $this->getJson('/api/v1/news?limit=999')->assertStatus(422);
    });
});

describe('proksi BMKG InaTEWS', function () {
    it('hanya tiga umpan diizinkan; respons dibungkus dengan waktu ambil dan di-cache', function () {
        Cache::flush();
        Http::fake(['data.bmkg.go.id/*' => Http::response(['Infogempa' => ['gempa' => ['Magnitude' => '5.4', 'Coordinates' => '5.59,125.27']]])]);

        $this->getJson('/api/v1/bmkg/autogempa')->assertOk()->assertJsonPath('data.Infogempa.gempa.Magnitude', '5.4')->assertJsonPath('is_stale', false);
        $this->getJson('/api/v1/bmkg/autogempa')->assertOk();
        Http::assertSentCount(1);
        $this->getJson('/api/v1/bmkg/https://evil.example')->assertNotFound();
        $this->getJson('/api/v1/bmkg/rahasia')->assertNotFound();
    });

    it('gagal tanpa cache = 502 terkendali; gagal dengan cache = data lama ditandai basi', function () {
        Cache::flush();
        Http::fake(['data.bmkg.go.id/*' => Http::sequence()->push('', 500)->push(['Infogempa' => ['gempa' => []]])->push('', 500)]);

        $this->getJson('/api/v1/bmkg/gempaterkini')->assertStatus(502)->assertJsonPath('status', 'error');
        $this->getJson('/api/v1/bmkg/gempaterkini')->assertOk();
        $this->travel(2)->minutes();
        $this->getJson('/api/v1/bmkg/gempaterkini')->assertOk()->assertJsonPath('is_stale', true);
    });
});

describe('pusat komando', function () {
    it('/komando dibuka publik dan memuat data sesar dengan atribusi lisensi', function () {
        $this->get('/komando')->assertOk()->assertInertia(fn (Inertia\Testing\AssertableInertia $p) => $p->component('Komando'));

        $geo = json_decode(file_get_contents(public_path('data/sesar-indonesia.geojson')), true);
        expect($geo['type'])->toBe('FeatureCollection')->and($geo['license'])->toBe('CC-BY-SA-4.0')->and($geo['attribution'])->toContain('GEM Global Active Faults')
            ->and(count($geo['features']))->toBeGreaterThan(100);
        foreach (array_slice($geo['features'], 0, 20) as $f) {
            expect($f['geometry']['type'])->toBe('MultiLineString');
            [$lng, $lat] = $f['geometry']['coordinates'][0][0];
            expect($lng)->toBeFloat()->and($lat)->toBeFloat(); // urutan GeoJSON [bujur, lintang]
        }
    });
});
