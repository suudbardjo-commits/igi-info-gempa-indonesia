<?php

use App\Enums\Role;
use App\Events\CampThreatenedEvent;
use App\Events\EarthquakeDetectedEvent;
use App\Events\LogisticsAlertEvent;
use App\Jobs\CalculateDisasterImpactJob;
use App\Models\AlertDelivery;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Alerts\AlertMessageBuilder;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    config(['services.telegram.bot_token' => '123:TOKEN']);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    Event::fake([CampThreatenedEvent::class, LogisticsAlertEvent::class]);

    // Pusat gempa di (113.20, -8.10). Posko A dan B dekat, C jauh (Jakarta).
    $this->campA = lgCamp('PSK-A', 113.22, -8.11);
    $this->campB = lgCamp('PSK-B', 113.30, -8.15);
    $this->campC = lgCamp('PSK-C', 106.85, -6.20);

    $this->koordA = User::factory()->create(['name' => 'Koordinator A'])->assignRole(Role::CampCoordinator->value);
    $this->koordB = User::factory()->create(['name' => 'Koordinator B'])->assignRole(Role::CampCoordinator->value);
    $this->koordC = User::factory()->create()->assignRole(Role::CampCoordinator->value);
    $this->campA->update(['coordinator_id' => $this->koordA->id]);
    $this->campB->update(['coordinator_id' => $this->koordB->id]);
    $this->campC->update(['coordinator_id' => $this->koordC->id]);

    $this->operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);

    lgLinkTelegram($this->koordA, 2001);
    lgLinkTelegram($this->koordC, 2003);   // koordinator B sengaja belum menautkan Telegram
    lgLinkTelegram($this->operator, 9001);
});

/** Mengganti total balasan Telegram (Http::fake kedua tidak menimpa yang pertama: stub pertama yang cocok menang). */
function tgReplaceFake(mixed $response): void
{
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['api.telegram.org/*' => $response]);
}

function runAlerts(App\Models\DisasterEvent $quake): void
{
    CalculateDisasterImpactJob::dispatchSync($quake->id);
}

function telegramTo(int $chatId): Illuminate\Support\Collection
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === $chatId)
        ->map(fn ($pair) => $pair[0]['text']);
}

describe('siapa yang diberi tahu', function () {
    it('menyiarkan ke posko yang terdampak saja, dan ke konsol logistik sekali', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10));

        Event::assertDispatched(CampThreatenedEvent::class, 2);
        Event::assertDispatched(CampThreatenedEvent::class, fn ($e) => $e->campId === $this->campA->id);
        Event::assertDispatched(CampThreatenedEvent::class, fn ($e) => $e->campId === $this->campB->id);
        Event::assertNotDispatched(CampThreatenedEvent::class, fn ($e) => $e->campId === $this->campC->id);
        Event::assertDispatchedTimes(LogisticsAlertEvent::class, 1);
    });

    it('mengirim Telegram ke koordinator yang postonya terdampak, dengan nama posko dan jarak', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10));

        $text = telegramTo(2001)->first();

        expect(telegramTo(2001))->toHaveCount(1)
            ->and($text)->toContain('Peringatan gempa M 6,3')->toContain('Posko <b>Posko PSK-A</b>')->toContain('km dari pusat gempa')->toContain('radius dampak 60 km');
    });

    it('tidak mengirim ke koordinator posko yang jauh dari gempa', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10));

        expect(telegramTo(2003))->toHaveCount(0);
    });

    it('mencatat koordinator yang belum menautkan Telegram sebagai dilewati, bukan menghilang', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10));

        $row = AlertDelivery::where('user_id', $this->koordB->id)->first();

        expect($row->status)->toBe('skipped')->and($row->error)->toContain('belum ditautkan');
        Http::assertNotSent(fn (HttpRequest $r) => ($r['chat_id'] ?? null) === null && str_ends_with($r->url(), '/sendMessage'));
    });

    it('memberi tahu operator untuk gempa besar walau tidak ada posko di dekatnya', function () {
        // Gempa M6,1 di tengah laut, jauh dari semua posko.
        runAlerts(lgQuake(6.1, 125.0, -5.0));

        expect(telegramTo(9001))->toHaveCount(1)
            ->and(telegramTo(9001)->first())->toContain('Tidak ada posko terdaftar di dalam radius dampak');
        Event::assertNotDispatched(CampThreatenedEvent::class);
        Event::assertNotDispatched(LogisticsAlertEvent::class);
    });

    it('tidak mengganggu operator untuk gempa kecil, tapi koordinator posko terdampak tetap diberi tahu', function () {
        runAlerts(lgQuake(4.6, 113.21, -8.105));   // radius 15 km: hanya posko A

        expect(telegramTo(2001))->toHaveCount(1)->and(telegramTo(9001))->toHaveCount(0);
    });

    it('memberi tahu operator untuk gempa kecil yang berpotensi tsunami', function () {
        runAlerts(lgQuake(4.8, 125.0, -5.0, ['tsunami_potential' => 'Berpotensi tsunami untuk diteruskan pada masyarakat']));

        expect(telegramTo(9001))->toHaveCount(1)->and(telegramTo(9001)->first())->toContain('Berpotensi tsunami');
    });

    it('mencantumkan operator hanya sekali walau ada banyak posko terdampak', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10));

        expect(telegramTo(9001))->toHaveCount(1)->and(telegramTo(9001)->first())->toContain('2 posko terdaftar');
    });
});

describe('aturan keamanan peringatan', function () {
    it('tidak mengirim apa pun untuk gempa lama', function () {
        runAlerts(lgQuake(6.8, 113.20, -8.10, ['occurred_at' => now()->subHours(5)]));

        Event::assertNotDispatched(CampThreatenedEvent::class);
        Event::assertNotDispatched(LogisticsAlertEvent::class);
        Http::assertNothingSent();
        expect(AlertDelivery::count())->toBe(0);
    });

    it('tidak mengirim untuk laporan duplikat dari sumber lain', function () {
        $utama = lgQuake(6.3, 113.20, -8.10);
        $dup = lgQuake(6.2, 113.21, -8.11, ['source' => 'usgs', 'canonical_event_id' => $utama->id]);

        runAlerts($dup);

        Event::assertNotDispatched(CampThreatenedEvent::class);
        Http::assertNothingSent();
    });

    it('tidak mengirim ulang saat penilaian dampak dijalankan lagi (job di-retry)', function () {
        $quake = lgQuake(6.3, 113.20, -8.10);

        runAlerts($quake);
        runAlerts($quake);
        runAlerts($quake);

        expect(telegramTo(2001))->toHaveCount(1)->and(telegramTo(9001))->toHaveCount(1);
        Event::assertDispatchedTimes(CampThreatenedEvent::class, 2);
        Event::assertDispatchedTimes(LogisticsAlertEvent::class, 1);
        expect(AlertDelivery::where('status', 'sent')->count())->toBe(2);
    });

    it('menjaga satu catatan pengiriman per orang per gempa lewat kunci unik database', function () {
        $quake = lgQuake(6.3, 113.20, -8.10);
        runAlerts($quake);

        expect(fn () => DB::table('alert_deliveries')->insert([
            'id' => (string) Illuminate\Support\Str::uuid(), 'disaster_event_id' => $quake->id, 'user_id' => $this->koordA->id,
            'channel' => 'telegram', 'reason' => 'camp_coordinator', 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });

    it('meng-escape teks dari luar di pesan Telegram', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10, ['location_name' => 'Dekat <script>alert(1)</script> & "kota"']));

        $text = telegramTo(2001)->first();

        expect($text)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; "kota"')->not->toContain('<script>');
    });

    it('tidak membuat pengiriman Telegram saat bot nonaktif, tapi siaran WebSocket tetap jalan', function () {
        config(['services.telegram.bot_token' => null]);

        runAlerts(lgQuake(6.3, 113.20, -8.10));

        Http::assertNothingSent();
        Event::assertDispatched(CampThreatenedEvent::class, 2);
        expect(AlertDelivery::where('status', 'skipped')->count())->toBe(AlertDelivery::count())
            ->and(AlertDelivery::first()->error)->toContain('nonaktif');
    });
});

describe('kegagalan pengiriman', function () {
    it('mencabut tautan dan mencatat gagal saat pengguna memblokir bot', function () {
        tgReplaceFake(Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403));

        runAlerts(lgQuake(6.3, 113.20, -8.10));

        $row = AlertDelivery::where('user_id', $this->koordA->id)->first();

        expect($row->status)->toBe('failed')->and($row->error)->toContain('blocked')
            ->and(TelegramLink::where('user_id', $this->koordA->id)->first()->isLinked())->toBeFalse();
    });

    it('menandai gagal tanpa mencoba ulang untuk permintaan yang ditolak Telegram', function () {
        tgReplaceFake(Http::response(['ok' => false, 'description' => "Bad Request: can't parse entities"], 400));

        runAlerts(lgQuake(6.3, 113.20, -8.10));

        expect(AlertDelivery::where('user_id', $this->koordA->id)->first()->status)->toBe('failed');
    });

    it('membiarkan pengiriman tetap pending saat kena batas laju, lalu berhasil pada percobaan berikutnya', function () {
        Http::swap(new Illuminate\Http\Client\Factory);
        Http::fakeSequence('api.telegram.org/*')
            ->push(['ok' => false, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 3]], 429)
            ->push(['ok' => true]);

        $delivery = AlertDelivery::create([
            'disaster_event_id' => lgQuake(6.3, 113.20, -8.10)->id, 'user_id' => $this->koordA->id,
            'channel' => 'telegram', 'reason' => 'camp_coordinator', 'status' => 'pending',
        ]);

        App\Jobs\SendEmergencyAlertNotificationJob::dispatchSync($delivery->id, 'teks');
        expect($delivery->refresh()->status)->toBe('pending')->and($delivery->attempts)->toBe(1)->and($delivery->error)->toContain('Too Many');

        App\Jobs\SendEmergencyAlertNotificationJob::dispatchSync($delivery->id, 'teks');
        expect($delivery->refresh()->status)->toBe('sent')->and($delivery->attempts)->toBe(2)->and($delivery->sent_at)->not->toBeNull()->and($delivery->error)->toBeNull();
    });

    it('tidak mengirim ulang pengiriman yang sudah berhasil', function () {
        $delivery = AlertDelivery::create([
            'disaster_event_id' => lgQuake(6.3, 113.20, -8.10)->id, 'user_id' => $this->koordA->id,
            'channel' => 'telegram', 'reason' => 'camp_coordinator', 'status' => 'sent', 'sent_at' => now(),
        ]);

        App\Jobs\SendEmergencyAlertNotificationJob::dispatchSync($delivery->id, 'teks');

        Http::assertNothingSent();
    });

    it('melewati pengiriman bila pengguna memutus Telegram setelah job diantrekan', function () {
        $delivery = AlertDelivery::create([
            'disaster_event_id' => lgQuake(6.3, 113.20, -8.10)->id, 'user_id' => $this->koordA->id,
            'channel' => 'telegram', 'reason' => 'camp_coordinator', 'status' => 'pending',
        ]);
        TelegramLink::where('user_id', $this->koordA->id)->update(['chat_id' => null]);

        App\Jobs\SendEmergencyAlertNotificationJob::dispatchSync($delivery->id, 'teks');

        Http::assertNothingSent();
        expect($delivery->refresh()->status)->toBe('skipped');
    });
});

describe('isi siaran WebSocket', function () {
    it('membawa data gempa, posko, jarak, dan koordinat pusat gempa', function () {
        $quake = lgQuake(6.3, 113.20, -8.10, ['location_name' => 'Dekat Lumajang']);

        runAlerts($quake);

        Event::assertDispatched(CampThreatenedEvent::class, function ($e) use ($quake) {
            if ($e->campId !== $this->campA->id) {
                return false;
            }

            $p = $e->broadcastWith();

            return $p['disaster']['id'] === $quake->id
                && $p['disaster']['magnitude'] === 6.3
                && $p['disaster']['location'] === 'Dekat Lumajang'
                && abs($p['disaster']['latitude'] - -8.10) < 0.001
                && abs($p['disaster']['longitude'] - 113.20) < 0.001
                && $p['camp']['name'] === 'Posko PSK-A'
                && $p['distance_km'] > 0 && $p['distance_km'] < 10
                && $e->broadcastQueue() === 'alerts';
        });
    });

    it('menyertakan daftar posko terdampak untuk konsol logistik, terurut jarak', function () {
        runAlerts(lgQuake(6.3, 113.20, -8.10));

        Event::assertDispatched(LogisticsAlertEvent::class, function ($e) {
            $names = array_column($e->camps, 'name');

            return $names === ['Posko PSK-A', 'Posko PSK-B'];
        });
    });
});

describe('alur lengkap dari ingesti', function () {
    it('menjalankan gempa baru dari BMKG sampai peringatan ke koordinator dan operator', function () {
        Event::fake([CampThreatenedEvent::class, LogisticsAlertEvent::class]);
        $at = now()->subMinutes(3)->utc()->format('Y-m-d\TH:i:sP');

        Http::fake([
            'data.bmkg.go.id/*' => Http::response(['Infogempa' => ['gempa' => [[
                'DateTime' => $at, 'Coordinates' => '-8.10,113.20', 'Magnitude' => '6.2', 'Kedalaman' => '12 km',
                'Wilayah' => 'Pusat gempa berada di laut 20 km Tenggara LUMAJANG', 'Potensi' => 'Tidak berpotensi tsunami',
            ]]]]),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('ingest:bmkg')->assertSuccessful();

        expect(telegramTo(2001))->toHaveCount(1)->and(telegramTo(9001))->toHaveCount(1);
        Event::assertDispatched(CampThreatenedEvent::class, 2);
    });
});

describe('pembuat pesan', function () {
    it('menulis tanggal dan jam WIB dalam bahasa Indonesia, dan tidak memotong jumlah posko', function () {
        $quake = lgQuake(6.3, 113.20, -8.10, ['occurred_at' => Carbon\Carbon::parse('2026-10-24 05:46:00', 'UTC')]);

        $text = app(AlertMessageBuilder::class)->forCamp($quake, 'Posko X', 12.34);

        expect($text)->toContain('Terjadi 24 Oktober 2026, 12:46 WIB')->toContain('sekitar 12,3 km');
    });

    it('meringkas daftar posko untuk operator maksimal lima nama', function () {
        $quake = lgQuake(6.3, 113.20, -8.10);
        $camps = collect(range(1, 7))->map(fn ($i) => (object) ['name' => "Posko {$i}", 'distance_km' => $i * 1.5]);

        $text = app(AlertMessageBuilder::class)->forOperator($quake, $camps);

        expect($text)->toContain('7 posko terdaftar')->toContain('- Posko 5')->not->toContain('- Posko 6')->toContain('dan 2 posko lainnya');
    });
});
