<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->posko = lgCamp('PSK-A', 113.22, -8.11);
    $this->lain = lgCamp('PSK-B', 113.30, -8.15);

    $this->koordinator = User::factory()->create(['password' => 'rahasia-123'])->assignRole(Role::CampCoordinator->value);
    $this->posko->update(['coordinator_id' => $this->koordinator->id]);
});

describe('halaman akun dan pengalihan setelah masuk', function () {
    it('menolak tamu', function () {
        $this->get('/akun')->assertRedirect('/masuk');
    });

    it('menampilkan halaman akun bagi koordinator posko', function () {
        $this->actingAs($this->koordinator)->get('/akun')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Akun'));
    });

    it('mengarahkan koordinator ke aplikasi lapangan, dan petugas logistik ke konsol, setelah masuk', function () {
        $this->post('/masuk', ['email' => $this->koordinator->email, 'password' => 'rahasia-123'])->assertRedirect('/lapangan');
        auth()->logout();

        $gudang = User::factory()->create(['password' => 'rahasia-123'])->assignRole(Role::WarehouseManager->value);
        $this->post('/masuk', ['email' => $gudang->email, 'password' => 'rahasia-123'])->assertRedirect('/logistik');
    });

    it('mengarahkan koordinator yang sudah masuk dari /masuk ke aplikasi lapangan', function () {
        $this->actingAs($this->koordinator)->get('/masuk')->assertRedirect('/lapangan');
    });
});

describe('ringkasan akun (API)', function () {
    function ancam(App\Models\EvacuationCamp $camp, float $mag, array $extra = [], float $km = 4.2): App\Models\DisasterEvent
    {
        $quake = lgQuake($mag, 113.2, -8.1, $extra);
        DB::table('disaster_event_camp')->insert(['disaster_event_id' => $quake->id, 'camp_id' => $camp->id, 'distance_km' => $km, 'assessed_at' => now()]);

        return $quake;
    }

    it('menampilkan posko yang diampu beserta peringatannya, terbaru lebih dulu', function () {
        ancam($this->posko, 5.5, ['occurred_at' => now()->subHours(5), 'location_name' => 'Lama']);
        ancam($this->posko, 6.3, ['occurred_at' => now()->subMinutes(10), 'location_name' => 'Baru']);

        Sanctum::actingAs($this->koordinator);

        $res = $this->getJson('/api/v1/me/overview')->assertOk();

        expect($res->json('camps'))->toHaveCount(1)->and($res->json('camps.0.code'))->toBe('PSK-A')
            ->and(array_column(array_column($res->json('alerts'), 'disaster'), 'location'))->toBe(['Baru', 'Lama'])
            ->and($res->json('alerts.0.distance_km'))->toBe(4.2)
            ->and($res->json('alerts.0.camp.name'))->toBe('Posko PSK-A');
    });

    it('tidak menampilkan peringatan posko lain', function () {
        ancam($this->lain, 6.3, ['location_name' => 'Posko lain']);

        Sanctum::actingAs($this->koordinator);

        expect($this->getJson('/api/v1/me/overview')->json('alerts'))->toBe([]);
    });

    it('mengabaikan gempa lebih dari 72 jam dan laporan duplikat', function () {
        ancam($this->posko, 6.0, ['occurred_at' => now()->subHours(80)]);
        $utama = lgQuake(6.1, 113.2, -8.1);
        ancam($this->posko, 6.1, ['source' => 'usgs', 'canonical_event_id' => $utama->id]);

        Sanctum::actingAs($this->koordinator);

        expect($this->getJson('/api/v1/me/overview')->json('alerts'))->toBe([]);
    });

    it('memberi daftar kosong bagi pengguna yang bukan koordinator posko', function () {
        Sanctum::actingAs(User::factory()->create()->assignRole(Role::FieldVolunteer->value));

        $this->getJson('/api/v1/me/overview')->assertOk()->assertJson(['camps' => [], 'alerts' => []]);
    });

    it('membutuhkan login', function () {
        $this->getJson('/api/v1/me/overview')->assertUnauthorized();
    });
});
