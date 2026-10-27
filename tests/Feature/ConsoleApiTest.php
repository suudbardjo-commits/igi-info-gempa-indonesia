<?php

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->posko = lgCamp('PSK-A', 113.2, -8.1);
    $this->gudang = lgWarehouse('GDG-A', 113.3, -8.1);
    $this->beras = lgItem('BERAS');

    $this->manajer = User::factory()->create()->assignRole(Role::WarehouseManager->value);
    $this->operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);
    $this->koordinator = User::factory()->create()->assignRole(Role::CampCoordinator->value);
    $this->gudang->update(['manager_id' => $this->manajer->id]);
    $this->posko->update(['coordinator_id' => $this->koordinator->id]);
});

describe('ringkasan', function () {
    it('menghitung permintaan terbuka, kritis, pengiriman aktif, dan batch yang segera kedaluwarsa', function () {
        lgStock($this->gudang, $this->beras, 'SEGERA', 100, now()->addDays(5));
        lgStock($this->gudang, $this->beras, 'AMAN', 100, now()->addMonths(5));
        lgDemand($this->posko, $this->beras, 10, 'critical');
        lgDemand($this->posko, $this->beras, 10, 'low');
        $selesai = lgDemand($this->posko, $this->beras, 10, 'critical');
        $selesai->update(['status' => 'fulfilled']);
        lgDispatch($this->gudang, $this->posko);

        lgLogin($this->manajer);

        $this->getJson('/api/v1/logistics/summary')->assertOk()->assertExactJson([
            'demands_open' => 2, 'demands_critical' => 1, 'dispatches_active' => 1,
            'batches_expiring' => 1, 'batches_expired' => 0,
        ]);
    });

    it('menghitung batch yang sudah lewat kedaluwarsa dan masih bersisa', function () {
        $inv = lgStock($this->gudang, $this->beras, 'LAMA', 50, now()->addDays(2));
        DB::table('warehouse_inventories')->where('id', $inv->id)->update(['expiry_date' => now()->subDays(3)->toDateString()]);

        lgLogin($this->operator);

        $this->getJson('/api/v1/logistics/summary')->assertJsonPath('batches_expired', 1)->assertJsonPath('batches_expiring', 0);
    });

    it('menolak yang bukan petugas logistik', function () {
        lgLogin($this->koordinator);

        $this->getJson('/api/v1/logistics/summary')->assertForbidden();
    });
});

describe('gudang dan barang', function () {
    it('menampilkan hanya gudang yang dikelola, dan semuanya bagi operator', function () {
        $lain = lgWarehouse('GDG-LAIN', 110, -7);
        $lain->update(['manager_id' => User::factory()->create()->assignRole(Role::WarehouseManager->value)->id]);

        lgLogin($this->manajer);
        $milik = $this->getJson('/api/v1/warehouses')->assertOk()->json('data');

        // Pengelola gudang melihat semua gudang (untuk koordinasi), tapi hanya satu yang bisa dikelolanya.
        expect($milik)->toHaveCount(2)
            ->and(collect($milik)->where('can_manage', true)->pluck('code')->all())->toBe(['GDG-A']);

        lgLogin($this->operator);
        $semua = $this->getJson('/api/v1/warehouses')->json('data');
        expect($semua)->toHaveCount(2)->and(collect($semua)->where('can_manage', true))->toHaveCount(0);
    });

    it('operator boleh melihat stok gudang tapi tidak menambahnya', function () {
        lgStock($this->gudang, $this->beras, 'B-1', 100, now()->addMonths(3));

        lgLogin($this->operator);
        $this->getJson("/api/v1/warehouses/{$this->gudang->id}/stock")->assertOk()
            ->assertJsonPath('can_manage', false)->assertJsonPath('data.0.quantity_free', 100);

        $this->postJson("/api/v1/warehouses/{$this->gudang->id}/stock", [
            'item_id' => $this->beras->id, 'batch_number' => 'X', 'quantity' => 5,
        ])->assertForbidden();
    });

    it('koordinator posko tidak boleh melihat stok gudang', function () {
        lgLogin($this->koordinator);

        $this->getJson("/api/v1/warehouses/{$this->gudang->id}/stock")->assertForbidden();
    });

    it('pengelola gudang yang bukan pemiliknya boleh melihat tapi bendera can_manage salah', function () {
        lgStock($this->gudang, $this->beras, 'B-1', 100, now()->addMonths(3));
        $lain = User::factory()->create()->assignRole(Role::WarehouseManager->value);

        lgLogin($lain);
        $this->getJson("/api/v1/warehouses/{$this->gudang->id}/stock")->assertOk()->assertJsonPath('can_manage', false);
    });

    it('menampilkan daftar barang beserta kategorinya', function () {
        lgLogin($this->manajer);

        $this->getJson('/api/v1/items')->assertOk()->assertJsonPath('data.0.sku', 'BERAS')->assertJsonPath('data.0.category', 'Pangan');
    });
});

describe('bendera hak aksi pada pengiriman', function () {
    it('menunjukkan aksi yang boleh dilakukan masing-masing peran', function () {
        lgStock($this->gudang, $this->beras, 'B-1', 100, now()->addMonths(3));
        $demand = lgDemand($this->posko, $this->beras, 50);

        lgLogin($this->manajer);
        $id = $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->json('dispatches.0.id');
        $this->getJson("/api/v1/dispatches/{$id}")->assertJsonPath('data.can', ['send' => true, 'cancel' => true, 'receive' => false]);

        lgLogin($this->operator);
        $this->getJson("/api/v1/dispatches/{$id}")->assertJsonPath('data.can', ['send' => false, 'cancel' => false, 'receive' => true]);
    });
});

describe('filter permintaan', function () {
    it('status "open" mencakup menunggu dan sebagian terpenuhi saja', function () {
        lgDemand($this->posko, $this->beras, 10)->update(['status' => 'pending']);
        lgDemand($this->posko, $this->beras, 10)->update(['status' => 'partial']);
        lgDemand($this->posko, $this->beras, 10)->update(['status' => 'fulfilled']);
        lgDemand($this->posko, $this->beras, 10)->update(['status' => 'cancelled']);

        lgLogin($this->operator);

        expect($this->getJson('/api/v1/demands?status=open')->json('data'))->toHaveCount(2);
        expect($this->getJson('/api/v1/demands?status=fulfilled')->json('data'))->toHaveCount(1);
        $this->getJson('/api/v1/demands?status=sembarang')->assertUnprocessable();
    });
});

describe('jejak audit', function () {
    it('operator melihat jejak yang terbaca manusia, lengkap dengan pengguna dan lokasi', function () {
        lgStock($this->gudang, $this->beras, 'B-1', 100, now()->addMonths(3), $this->manajer);

        lgLogin($this->operator);
        $res = $this->getJson('/api/v1/audit-logs')->assertOk();

        $row = $res->json('data.0');
        expect($row['label'])->toBe('Stok masuk gudang')
            ->and($row['summary'])->toContain('BERAS', 'B-1', '+100.00')
            ->and($row['user']['email'])->toBe($this->manajer->email)
            ->and($res->json('meta.total'))->toBe(1);
    });

    it('menyaring menurut aksi', function () {
        lgStock($this->gudang, $this->beras, 'B-1', 100);
        $demand = lgDemand($this->posko, $this->beras, 50);
        lgLogin($this->manajer);
        $this->postJson("/api/v1/demands/{$demand->id}/dispatches")->assertCreated();

        lgLogin($this->operator);
        $this->getJson('/api/v1/audit-logs?action=DISPATCH_CREATED')->assertJsonCount(1, 'data');
        expect($this->getJson('/api/v1/audit-logs')->json('data'))->toHaveCount(3);
    });

    it('menolak pengelola gudang dan koordinator', function (string $who) {
        lgLogin($this->{$who});

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/audit-logs/integrity')->assertForbidden();
    })->with(['manajer', 'koordinator']);

    it('melaporkan integritas utuh, lalu mendeteksi saldo yang diubah di luar ledger', function () {
        $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);

        lgLogin($this->operator);
        $this->getJson('/api/v1/audit-logs/integrity')->assertOk()
            ->assertJsonPath('audit.ok', true)->assertJsonPath('audit.total', 1)->assertJsonPath('ledger.ok', true);

        DB::table('warehouse_inventories')->where('id', $inv->id)->update(['quantity_available' => 999]);

        $this->getJson('/api/v1/audit-logs/integrity')->assertJsonPath('ledger.ok', false)
            ->assertJsonPath('ledger.mismatches.0.field', 'tersedia');
    });

    it('tidak gagal untuk baris audit dengan isi yang tak terduga', function () {
        app(App\Services\Logistics\AuditLogger::class)->record('INVENTORY_IN', 'x', (string) Illuminate\Support\Str::uuid(), null, ['aneh' => 1]);

        lgLogin($this->operator);

        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('data.0.summary', '');
    });
});
