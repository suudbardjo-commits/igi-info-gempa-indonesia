<?php

use App\Models\User;
use App\Services\Logistics\AllocationEngine;
use App\Services\Logistics\DispatchService;
use App\Services\Logistics\InventoryLedgerService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->posko = lgCamp('PSK-A', 113.2, -8.1);
    $this->gudang = lgWarehouse('GDG-A', 113.3, -8.1);
    $this->beras = lgItem('BERAS');
    $this->user = User::factory()->create();
});

it('menyatakan semua cocok setelah satu siklus pengiriman penuh dan sebagian dibatalkan', function () {
    lgStock($this->gudang, $this->beras, 'B-1', 500, now()->addMonths(3));
    $engine = app(AllocationEngine::class);
    $svc = app(DispatchService::class);

    $d1 = lgDemand($this->posko, $this->beras, 200);
    $first = $svc->createFromPlan($d1, $engine->plan($d1), $this->user)->first();
    $svc->send($first, $this->user);
    $svc->receive($first, $this->user->assignRole('super-admin'), ['lat' => -8.1, 'lng' => 113.2, 'accuracy' => 5]);

    $d2 = lgDemand($this->posko, $this->beras, 100);
    $second = $svc->createFromPlan($d2, $engine->plan($d2), $this->user)->first();
    $svc->cancel($second, $this->user, 'batal');

    $d3 = lgDemand($this->posko, $this->beras, 50);
    $svc->createFromPlan($d3, $engine->plan($d3), $this->user);   // dibiarkan draft: stok direservasi

    $this->artisan('ledger:reconcile')->expectsOutputToContain('cocok')->assertSuccessful();
});

it('mendeteksi stok yang diubah langsung di tabel tanpa lewat ledger', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);

    DB::table('warehouse_inventories')->where('id', $inv->id)->update(['quantity_available' => 250]);

    $this->artisan('ledger:reconcile')->expectsOutputToContain('1 saldo tidak cocok')->assertFailed();
});

it('mendeteksi reservasi dan stok posko yang tidak sesuai ledger', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);
    app(InventoryLedgerService::class)->receiveIntoCamp($this->posko, $this->beras, 20, lgDispatch($this->gudang, $this->posko));

    DB::table('warehouse_inventories')->where('id', $inv->id)->update(['quantity_reserved' => 40]);
    DB::table('camp_inventories')->update(['quantity_on_hand' => 99]);

    $mismatches = app(App\Services\Logistics\LedgerReconciler::class)->mismatches();

    expect(array_column($mismatches, 'field'))->toContain('direservasi', 'di tangan');
});
