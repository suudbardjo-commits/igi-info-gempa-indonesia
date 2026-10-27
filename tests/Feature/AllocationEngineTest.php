<?php

use App\Enums\DemandStatus;
use App\Models\ReliefDispatchItem;
use App\Services\Logistics\AllocationEngine;
use App\Services\Logistics\InventoryLedgerService;

beforeEach(function () {
    $this->engine = app(AllocationEngine::class);
    $this->posko = lgCamp('PSK-LMJ', 113.20, -8.10);              // Lumajang
    $this->dekat = lgWarehouse('GDG-DEKAT', 113.30, -8.10);        // sekitar 11 km
    $this->sedang = lgWarehouse('GDG-SBY', 112.75, -7.25);         // Surabaya, sekitar 110 km
    $this->jauh = lgWarehouse('GDG-SMG', 110.42, -7.00);           // Semarang, sekitar 330 km
    $this->beras = lgItem('BERAS', perishable: true);
});

function sourceCodes($plan): array
{
    return array_column($plan->toArray()['sources'], 'warehouse_code');
}

it('memilih gudang terdekat lebih dulu walau gudang jauh punya stok lebih banyak', function () {
    lgStock($this->jauh, $this->beras, 'J-1', 5000);
    lgStock($this->dekat, $this->beras, 'D-1', 500);

    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 300));

    expect(sourceCodes($plan))->toBe(['GDG-DEKAT'])
        ->and($plan->isComplete())->toBeTrue()
        ->and($plan->allocated)->toBe('300.00');
});

it('memakai batch yang paling cepat kedaluwarsa lebih dulu di dalam satu gudang (FEFO)', function () {
    lgStock($this->dekat, $this->beras, 'LAMBAT', 200, now()->addMonths(8));
    lgStock($this->dekat, $this->beras, 'CEPAT', 200, now()->addDays(30));
    lgStock($this->dekat, $this->beras, 'TANPA-TANGGAL', 200, null);

    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 300));
    $lines = $plan->toArray()['sources'][0]['lines'];

    expect(array_column($lines, 'batch_number'))->toBe(['CEPAT', 'LAMBAT'])
        ->and(array_column($lines, 'quantity'))->toBe(['200.00', '100.00']);
});

it('membagi kebutuhan ke gudang berikutnya kalau gudang terdekat tidak cukup', function () {
    lgStock($this->dekat, $this->beras, 'D-1', 120);
    lgStock($this->sedang, $this->beras, 'S-1', 500);
    lgStock($this->jauh, $this->beras, 'J-1', 500);

    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 400));
    $sources = $plan->toArray()['sources'];

    expect(sourceCodes($plan))->toBe(['GDG-DEKAT', 'GDG-SBY'])
        ->and(array_column($sources, 'quantity'))->toBe(['120.00', '280.00'])
        ->and((float) $sources[0]['distance_km'])->toBeLessThan((float) $sources[1]['distance_km']);
});

it('melaporkan kekurangan kalau seluruh stok tidak cukup', function () {
    lgStock($this->dekat, $this->beras, 'D-1', 100);
    lgStock($this->sedang, $this->beras, 'S-1', 50);

    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 400));

    expect($plan->allocated)->toBe('150.00')
        ->and($plan->shortfall)->toBe('250.00')
        ->and($plan->isComplete())->toBeFalse();
});

it('tidak menghitung stok yang sudah direservasi pengiriman lain', function () {
    $inv = lgStock($this->dekat, $this->beras, 'D-1', 100);
    app(InventoryLedgerService::class)->reserve($inv, 80, lgDispatch($this->dekat, $this->posko));
    lgStock($this->sedang, $this->beras, 'S-1', 500);

    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 150));
    $sources = $plan->toArray()['sources'];

    expect(sourceCodes($plan))->toBe(['GDG-DEKAT', 'GDG-SBY'])
        ->and($sources[0]['quantity'])->toBe('20.00')      // hanya sisa bebas
        ->and($sources[1]['quantity'])->toBe('130.00');
});

it('melewati barang mudah rusak yang hampir kedaluwarsa, tapi tidak untuk barang awet', function () {
    $tenda = lgItem('TENDA', perishable: false);
    lgStock($this->dekat, $this->beras, 'HAMPIR', 500, now()->addDays(3));   // < 7 hari
    lgStock($this->dekat, $tenda, 'T-1', 10, now()->addDays(3));
    lgStock($this->sedang, $this->beras, 'AMAN', 500, now()->addDays(60));

    $beras = $this->engine->plan(lgDemand($this->posko, $this->beras, 100));
    $tendaPlan = $this->engine->plan(lgDemand($this->posko, $tenda, 5));

    expect(sourceCodes($beras))->toBe(['GDG-SBY'])
        ->and(sourceCodes($tendaPlan))->toBe(['GDG-DEKAT']);
});

it('membatasi jumlah gudang sumber', function () {
    config(['disaster.logistics.max_sources' => 2]);
    lgStock($this->dekat, $this->beras, 'D-1', 100);
    lgStock($this->sedang, $this->beras, 'S-1', 100);
    lgStock($this->jauh, $this->beras, 'J-1', 100);

    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 300));

    expect(sourceCodes($plan))->toBe(['GDG-DEKAT', 'GDG-SBY'])
        ->and($plan->shortfall)->toBe('100.00');
});

it('mengurangi kebutuhan dengan barang yang sudah dalam perjalanan untuk permintaan yang sama', function () {
    lgStock($this->dekat, $this->beras, 'D-1', 1000);
    $demand = lgDemand($this->posko, $this->beras, 500);
    $inv = $this->dekat->inventories()->first();

    $dispatch = lgDispatch($this->dekat, $this->posko, App\Enums\DispatchStatus::InTransit);
    ReliefDispatchItem::create([
        'dispatch_id' => $dispatch->id, 'inventory_id' => $inv->id, 'item_id' => $this->beras->id,
        'demand_id' => $demand->id, 'quantity_dispatched' => 350,
    ]);

    $demand->update(['quantity_fulfilled' => 50]);

    expect($this->engine->outstanding($demand->fresh()))->toBe('100.00')   // 500 - 50 - 350
        ->and($this->engine->plan($demand->fresh())->allocated)->toBe('100.00');
});

it('tidak mengalokasikan permintaan yang sudah dibatalkan atau terpenuhi', function () {
    lgStock($this->dekat, $this->beras, 'D-1', 1000);
    $demand = lgDemand($this->posko, $this->beras, 100);

    $demand->update(['status' => DemandStatus::Cancelled]);

    expect($this->engine->plan($demand->fresh())->isEmpty())->toBeTrue();
});

it('mengembalikan rencana kosong dengan kekurangan penuh kalau tidak ada stok sama sekali', function () {
    $plan = $this->engine->plan(lgDemand($this->posko, $this->beras, 100));

    expect($plan->isEmpty())->toBeTrue()
        ->and($plan->shortfall)->toBe('100.00')
        ->and($plan->allocated)->toBe('0.00');
});

it('tidak mengubah stok saat menghitung usulan', function () {
    $inv = lgStock($this->dekat, $this->beras, 'D-1', 100);

    $this->engine->plan(lgDemand($this->posko, $this->beras, 60));

    expect($inv->refresh()->quantity_reserved)->toBe(0.0);
});
