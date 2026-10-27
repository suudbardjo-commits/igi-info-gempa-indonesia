<?php

use App\Enums\Role;
use App\Models\ExternalNews;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

describe('tentang data dan status sumber', function () {
    it('menampilkan empat sumber; yang belum pernah berhasil diambil ditandai tertunda', function () {
        $this->get('/tentang')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Tentang')
            ->has('sources', 4)
            ->where('sources.0.key', 'bmkg')->where('sources.0.last_ok', null)->where('sources.0.stale', true));
    });

    it('sumber yang baru berhasil diambil normal, yang lama tertunda', function () {
        Cache::put('ingest:last_ok:bmkg', now()->subMinutes(3)->toIso8601String(), now()->addDay());
        Cache::put('ingest:last_ok:usgs', now()->subHours(2)->toIso8601String(), now()->addDay());
        ExternalNews::create(['source' => 'bnpb', 'external_id' => 'x', 'title' => 'T', 'url' => 'https://www.bnpb.go.id/berita/x', 'published_at' => now()->subDay(), 'fetched_at' => now()->subMinutes(20)]);

        $this->get('/tentang')->assertInertia(fn (Assert $p) => $p
            ->where('sources.0.stale', false)
            ->where('sources.1.stale', true)
            ->where('sources.2.stale', true)
            ->where('sources.3.stale', false));
    });

    it('perintah ingesti mencatat keberhasilan, dan kegagalan tidak memperbarui catatan', function () {
        Http::fake(['data.bmkg.go.id/*' => Http::response(['Infogempa' => ['gempa' => []]])]);
        $this->artisan('ingest:bmkg --feed=terkini')->assertSuccessful();
        expect(Cache::get('ingest:last_ok:bmkg'))->not->toBeNull();

        Cache::forget('ingest:last_ok:bmkg');
        Cache::forget('bmkg:etag:terkini');
        // Http::fake kedua tidak menimpa yang pertama; pabrik diganti dulu.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['data.bmkg.go.id/*' => Http::response('x', 500)]);
        $this->artisan('ingest:bmkg --feed=terkini')->assertFailed();
        expect(Cache::get('ingest:last_ok:bmkg'))->toBeNull();
    });
});

describe('umpan Atom', function () {
    it('berisi gempa terbaru dengan tautan ke halaman beritanya, sebagai XML yang sah', function () {
        $e = lgQuake(5.4, 113.2, -8.1);
        lgQuake(4.1, 112.0, -7.0, ['external_id' => 'LAMA', 'occurred_at' => now()->subDays(20)]);

        $res = $this->get('/feed.xml')->assertOk();

        expect($res->headers->get('Content-Type'))->toContain('application/atom+xml');

        $xml = simplexml_load_string($res->getContent());
        expect($xml)->not->toBeFalse()->and(count($xml->entry))->toBe(1)
            ->and((string) $xml->entry[0]->link['href'])->toBe(url('/berita/'.$e->id))
            ->and((string) $xml->entry[0]->title)->toContain('M 5,4');
    });

    it('mengamankan karakter khusus pada nama lokasi', function () {
        lgQuake(4.8, 113.2, -8.1, ['location_name' => 'Laut <Banda> & "Flores"']);

        $xml = simplexml_load_string($this->get('/feed.xml')->getContent());

        expect($xml)->not->toBeFalse()->and((string) $xml->entry[0]->title)->toContain('Laut <Banda> & "Flores"');
    });

    it('tetap sah saat belum ada gempa', function () {
        $xml = simplexml_load_string($this->get('/feed.xml')->assertOk()->getContent());

        expect($xml)->not->toBeFalse()->and(count($xml->entry))->toBe(0);
    });
});

describe('lencana pendaftaran di konsol', function () {
    it('hanya dihitung untuk yang berhak meninjau', function () {
        $pending = User::factory()->create();
        $pending->forceFill(['account_status' => 'pending'])->save();

        $operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);
        $gudang = User::factory()->create()->assignRole(Role::WarehouseManager->value);

        $this->actingAs($operator)->get('/logistik')->assertInertia(fn (Assert $p) => $p->where('pendingRegistrations', 1));
        $this->actingAs($gudang)->get('/logistik')->assertInertia(fn (Assert $p) => $p->where('pendingRegistrations', null));
        $this->app['auth']->forgetGuards();
        $this->get('/berita')->assertInertia(fn (Assert $p) => $p->where('pendingRegistrations', null));
    });
});
