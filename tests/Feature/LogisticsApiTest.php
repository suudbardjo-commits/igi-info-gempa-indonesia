<?php

use App\Enums\Role;
use App\Jobs\AutoMatchLogisticsJob;
use App\Models\CampDemand;
use App\Models\ReliefDispatch;
use App\Models\User;
use App\Services\Logistics\AuditLogger;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->posko = lgCamp('PSK-LMJ', 113.2000, -8.1000);
    $this->gudang = lgWarehouse('GDG-A', 113.30, -8.10);
    $this->beras = lgItem('BERAS');

    $this->koordinator = User::factory()->create()->assignRole(Role::CampCoordinator->value);
    $this->manajer = User::factory()->create()->assignRole(Role::WarehouseManager->value);
    $this->operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);
    $this->relawan = User::factory()->create()->assignRole(Role::FieldVolunteer->value);

    $this->posko->update(['coordinator_id' => $this->koordinator->id]);
    $this->gudang->update(['manager_id' => $this->manajer->id]);

    $this->diPosko = ['latitude' => -8.1000, 'longitude' => 113.2000, 'accuracy' => 10];
});

function lgLogin(User $user): void
{
    Sanctum::actingAs($user);
}

it('menolak semua endpoint logistik tanpa login', function (string $method, string $path) {
    $this->json($method, $path)->assertUnauthorized();
})->with([
    ['GET', '/api/v1/demands'],
    ['GET', '/api/v1/dispatches'],
    ['POST', '/api/v1/camps/00000000-0000-0000-0000-000000000000/demands'],
    ['POST', '/api/v1/dispatches/00000000-0000-0000-0000-000000000000/receive'],
]);

describe('permintaan posko', function () {
    it('membuat permintaan oleh koordinator posko dan menjadwalkan saran alokasi', function () {
        Bus::fake([AutoMatchLogisticsJob::class]);
        lgLogin($this->koordinator);

        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", [
            'item_id' => $this->beras->id, 'quantity_requested' => '250.5', 'urgency_level' => 'critical', 'notes' => 'Anak-anak belum makan',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.urgency_level', 'critical')
            ->assertJsonPath('data.item.sku', 'BERAS');

        Bus::assertDispatched(AutoMatchLogisticsJob::class);
    });

    it('menolak koordinator posko lain, relawan, dan petugas gudang', function (string $who) {
        lgLogin($this->{$who});

        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", [
            'item_id' => $this->beras->id, 'quantity_requested' => 10, 'urgency_level' => 'high',
        ])->assertForbidden();
    })->with(['relawan', 'manajer', 'operator']);

    it('menolak koordinator dari posko lain', function () {
        $lain = lgCamp('PSK-LAIN', 112.0, -7.0);
        $lain->update(['coordinator_id' => User::factory()->create()->assignRole(Role::CampCoordinator->value)->id]);
        lgLogin($this->koordinator);

        $this->postJson("/api/v1/camps/{$lain->id}/demands", [
            'item_id' => $this->beras->id, 'quantity_requested' => 10, 'urgency_level' => 'high',
        ])->assertForbidden();
    });

    it('memvalidasi isian', function () {
        lgLogin($this->koordinator);

        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", [
            'item_id' => 'bukan-uuid', 'quantity_requested' => -5, 'urgency_level' => 'darurat',
        ])->assertUnprocessable()->assertJsonValidationErrors(['item_id', 'quantity_requested', 'urgency_level']);
    });

    it('tidak menggandakan permintaan kalau dikirim ulang dengan ID yang sama (mode offline)', function () {
        lgLogin($this->koordinator);
        $id = (string) Str::uuid7();
        $body = ['id' => $id, 'item_id' => $this->beras->id, 'quantity_requested' => 40, 'urgency_level' => 'high'];

        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", $body)->assertCreated();
        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", $body)->assertOk()->assertJsonPath('data.id', $id);

        expect(CampDemand::count())->toBe(1);
    });

    it('menampilkan permintaan sesuai peran: koordinator hanya miliknya, operator semuanya, urut paling mendesak', function () {
        $lain = lgCamp('PSK-LAIN', 112.0, -7.0);
        lgDemand($this->posko, $this->beras, 10, 'low');
        lgDemand($this->posko, $this->beras, 20, 'critical');
        lgDemand($lain, $this->beras, 30, 'high');

        lgLogin($this->koordinator);
        $mine = $this->getJson('/api/v1/demands')->assertOk()->json('data');
        expect($mine)->toHaveCount(2)->and($mine[0]['urgency_level'])->toBe('critical');

        lgLogin($this->operator);
        expect($this->getJson('/api/v1/demands')->json('data'))->toHaveCount(3);

        lgLogin($this->relawan);
        expect($this->getJson('/api/v1/demands')->json('data'))->toHaveCount(0);
    });
});

describe('stok gudang', function () {
    it('mencatat stok masuk oleh pengelola gudang itu sendiri', function () {
        lgLogin($this->manajer);

        $this->postJson("/api/v1/warehouses/{$this->gudang->id}/stock", [
            'item_id' => $this->beras->id, 'batch_number' => 'B-77', 'quantity' => 1000, 'expiry_date' => now()->addMonths(6)->toDateString(),
        ])->assertCreated()->assertJsonPath('quantity_available', 1000)->assertJsonPath('quantity_free', 1000);

        expect(DB::table('logistics_audit_logs')->where('action', 'INVENTORY_IN')->count())->toBe(1);
    });

    it('menolak pengelola gudang lain, koordinator, dan kedaluwarsa di masa lalu', function () {
        $lain = lgWarehouse('GDG-LAIN', 110.0, -7.0);
        $lain->update(['manager_id' => User::factory()->create()->assignRole(Role::WarehouseManager->value)->id]);

        lgLogin($this->manajer);
        $this->postJson("/api/v1/warehouses/{$lain->id}/stock", ['item_id' => $this->beras->id, 'batch_number' => 'X', 'quantity' => 5])->assertForbidden();
        $this->postJson("/api/v1/warehouses/{$this->gudang->id}/stock", ['item_id' => $this->beras->id, 'batch_number' => 'X', 'quantity' => 5, 'expiry_date' => '2020-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors('expiry_date');

        lgLogin($this->koordinator);
        $this->postJson("/api/v1/warehouses/{$this->gudang->id}/stock", ['item_id' => $this->beras->id, 'batch_number' => 'X', 'quantity' => 5])->assertForbidden();
    });
});

describe('alur pengiriman lengkap lewat API', function () {
    it('menjalankan permintaan → rencana → draft → kirim → terima, dengan hasil yang konsisten', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 500, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 300, 'critical');

        // 1. Pengelola gudang melihat usulan alokasi.
        lgLogin($this->manajer);
        $this->getJson("/api/v1/demands/{$demand->id}/allocation-plan")->assertOk()
            ->assertJsonPath('complete', true)->assertJsonPath('allocated', '300.00')
            ->assertJsonPath('sources.0.warehouse_code', 'GDG-A');

        // 2. Membuat pengiriman draft (stok direservasi).
        $created = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->assertCreated()->assertJsonPath('complete', true);
        $dispatchId = $created->json('dispatches.0.id');
        expect((float) DB::table('warehouse_inventories')->value('quantity_reserved'))->toBe(300.0);

        // 3. Memberangkatkan.
        $this->postJson("/api/v1/dispatches/{$dispatchId}/send", ['driver_name' => 'Budi', 'vehicle_plate_number' => 'N 1 AB'])
            ->assertOk()->assertJsonPath('data.status', 'dispatched');

        // 4. Koordinator menerima di posko.
        lgLogin($this->koordinator);
        $itemId = ReliefDispatch::find($dispatchId)->items()->value('id');
        $this->postJson("/api/v1/dispatches/{$dispatchId}/receive", $this->diPosko + [
            'received' => [['dispatch_item_id' => $itemId, 'quantity' => 300]], 'notes' => 'Diterima lengkap',
        ])->assertOk()->assertJsonPath('data.status', 'received')->assertJsonPath('data.items.0.quantity_received', 300);

        expect((float) DB::table('camp_inventories')->value('quantity_on_hand'))->toBe(300.0)
            ->and($demand->refresh()->status->value)->toBe('fulfilled')
            ->and((float) DB::table('warehouse_inventories')->value('quantity_available'))->toBe(200.0)
            ->and(app(AuditLogger::class)->verify())->toBeNull();
    });

    it('menolak serah terima di luar geofence dengan jarak yang jelas, dan stok tetap aman', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');
        $this->postJson("/api/v1/dispatches/{$id}/send")->assertOk();

        lgLogin($this->koordinator);
        $this->postJson("/api/v1/dispatches/{$id}/receive", ['latitude' => -8.1090, 'longitude' => 113.2000, 'accuracy' => 10])
            ->assertUnprocessable()
            ->assertJsonPath('allowed_m', 500)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'di luar batas 500 m'));

        expect(DB::table('camp_inventories')->count())->toBe(0);
    });

    it('operator BPBD bisa menerima di luar geofence dengan alasan, dan itu tercatat', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');
        $this->postJson("/api/v1/dispatches/{$id}/send")->assertOk();

        lgLogin($this->operator);
        $far = ['latitude' => -8.1090, 'longitude' => 113.2000, 'accuracy' => 10];

        $this->postJson("/api/v1/dispatches/{$id}/receive", $far)->assertUnprocessable();
        $this->postJson("/api/v1/dispatches/{$id}/receive", $far + ['geofence_override_reason' => 'Posko dipindah ke tenda darurat'])->assertOk();

        $new = json_decode(DB::table('logistics_audit_logs')->where('action', 'DISPATCH_RECEIVED')->value('new_values'), true);
        expect($new['geofence_overridden'])->toBeTrue();
    });

    it('melarang pihak yang tidak berwenang mengirim, membatalkan, atau menerima', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');

        $lainManajer = User::factory()->create()->assignRole(Role::WarehouseManager->value);

        lgLogin($lainManajer);
        $this->postJson("/api/v1/dispatches/{$id}/send")->assertForbidden();
        $this->postJson("/api/v1/dispatches/{$id}/cancel", ['reason' => 'iseng'])->assertForbidden();
        // Pengelola gudang lain boleh melihat (koordinasi lintas gudang) tetapi tidak boleh mengubah.
        $this->getJson("/api/v1/dispatches/{$id}")->assertOk();

        lgLogin($this->relawan);
        $this->getJson("/api/v1/dispatches/{$id}")->assertForbidden();

        lgLogin($this->koordinator);
        $this->postJson("/api/v1/dispatches/{$id}/send")->assertForbidden();

        lgLogin($this->relawan);
        $this->postJson("/api/v1/dispatches/{$id}/receive", $this->diPosko)->assertForbidden();
    });

    it('melaporkan konflik 409 untuk status yang salah, bukan error server', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');
        $this->postJson("/api/v1/dispatches/{$id}/send")->assertOk();

        $this->postJson("/api/v1/dispatches/{$id}/send")->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'dispatched'));
        $this->postJson("/api/v1/dispatches/{$id}/cancel", ['reason' => 'terlambat'])->assertStatus(409);
    });

    it('membatalkan draft dan melepas reservasinya', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 80);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');

        $this->postJson("/api/v1/dispatches/{$id}/cancel", ['reason' => 'Salah posko'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/dispatches/{$id}/cancel", [])->assertUnprocessable();

        expect((float) DB::table('warehouse_inventories')->value('quantity_reserved'))->toBe(0.0);
    });

    it('menolak membuat pengiriman kalau tidak ada stok sama sekali', function () {
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->assertUnprocessable()
            ->assertJsonPath('plan.shortfall', '50.00');
    });

    it('menolak jumlah diterima yang melebihi kiriman dengan pesan 422', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');
        $this->postJson("/api/v1/dispatches/{$id}/send")->assertOk();
        $itemId = ReliefDispatch::find($id)->items()->value('id');

        lgLogin($this->koordinator);
        $this->postJson("/api/v1/dispatches/{$id}/receive", $this->diPosko + ['received' => [['dispatch_item_id' => $itemId, 'quantity' => 500]]])
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'melebihi'));
    });

    it('menghitung saran alokasi di antrean untuk permintaan baru', function () {
        lgStock($this->gudang, $this->beras, 'A-1', 100, now()->addMonths(3));
        lgLogin($this->koordinator);

        // Antrean "sync" pada tes, jadi job berjalan langsung.
        $this->postJson("/api/v1/camps/{$this->posko->id}/demands", [
            'item_id' => $this->beras->id, 'quantity_requested' => 150, 'urgency_level' => 'high',
        ])->assertCreated();

        $demand = CampDemand::first();
        expect($demand->suggested_plan['allocated'])->toBe('100.00')
            ->and($demand->suggested_plan['shortfall'])->toBe('50.00')
            ->and($demand->suggested_at)->not->toBeNull();

        $this->getJson('/api/v1/demands')->assertJsonPath('data.0.suggestion.complete', false)
            ->assertJsonPath('data.0.suggestion.shortfall', '50.00');
    });
});
