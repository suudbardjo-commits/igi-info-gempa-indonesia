<?php

use App\Enums\DemandStatus;
use App\Enums\DispatchStatus;
use App\Enums\Role;
use App\Exceptions\DispatchStateException;
use App\Exceptions\GeofenceViolationException;
use App\Exceptions\InsufficientStockException;
use App\Models\User;
use App\Services\Logistics\AllocationEngine;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\DispatchService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->engine = app(AllocationEngine::class);
    $this->svc = app(DispatchService::class);

    $this->posko = lgCamp('PSK-LMJ', 113.2000, -8.1000);
    $this->gudangA = lgWarehouse('GDG-A', 113.30, -8.10);
    $this->gudangB = lgWarehouse('GDG-B', 112.75, -7.25);
    $this->beras = lgItem('BERAS');
    $this->mie = lgItem('MIE');

    $this->petugas = User::factory()->create()->assignRole(Role::WarehouseManager->value);
    $this->koordinator = User::factory()->create()->assignRole(Role::CampCoordinator->value);
    $this->operator = User::factory()->create()->assignRole(Role::BpbdOperator->value);

    // Titik GPS di posko, dan titik sekitar 1 km dari posko (lintang berbeda ~0,009 derajat).
    $this->diPosko = ['lat' => -8.1000, 'lng' => 113.2000, 'accuracy' => 10.0];
    $this->satuKm = ['lat' => -8.1090, 'lng' => 113.2000, 'accuracy' => 10.0];
});

function newDraft($t, float $beras = 300): array
{
    lgStock($t->gudangA, $t->beras, 'A-1', 200, now()->addMonths(3));
    lgStock($t->gudangB, $t->beras, 'B-1', 500, now()->addMonths(3));
    $demand = lgDemand($t->posko, $t->beras, $beras);

    $dispatches = $t->svc->createFromPlan($demand, $t->engine->plan($demand), $t->petugas);

    return [$demand, $dispatches];
}

it('membuat satu pengiriman draft per gudang sumber dan mereservasi stoknya', function () {
    [$demand, $dispatches] = newDraft($this);

    expect($dispatches)->toHaveCount(2)
        ->and($dispatches->pluck('status')->unique()->all())->toBe([DispatchStatus::Draft])
        ->and($dispatches->first()->dispatch_code)->toStartWith('DSP-');

    $reserved = DB::table('warehouse_inventories')->pluck('quantity_reserved', 'batch_number');
    expect((float) $reserved['A-1'])->toBe(200.0)->and((float) $reserved['B-1'])->toBe(100.0);

    // Stok belum keluar dari gudang, dan item pengiriman tertaut ke permintaan.
    expect((float) DB::table('warehouse_inventories')->sum('quantity_available'))->toBe(700.0)
        ->and(DB::table('relief_dispatch_items')->where('demand_id', $demand->id)->count())->toBe(2);
});

it('tidak membuat apa pun kalau stok berubah di tengah jalan (semua atau tidak sama sekali)', function () {
    lgStock($this->gudangA, $this->beras, 'A-1', 200, now()->addMonths(3));
    $invB = lgStock($this->gudangB, $this->beras, 'B-1', 500, now()->addMonths(3));
    $demand = lgDemand($this->posko, $this->beras, 300);

    $plan = $this->engine->plan($demand);

    // Orang lain mereservasi hampir seluruh stok gudang B setelah rencana dihitung.
    app(App\Services\Logistics\InventoryLedgerService::class)->reserve($invB, 450, lgDispatch($this->gudangB, $this->posko));

    expect(fn () => $this->svc->createFromPlan($demand, $plan, $this->petugas))
        ->toThrow(InsufficientStockException::class);

    expect(DB::table('relief_dispatches')->count())->toBe(1)          // hanya milik "orang lain"
        ->and((float) DB::table('warehouse_inventories')->where('batch_number', 'A-1')->value('quantity_reserved'))->toBe(0.0);
});

it('menolak rencana kosong atau rencana milik permintaan lain', function () {
    $demand = lgDemand($this->posko, $this->beras, 100);
    $other = lgDemand($this->posko, $this->mie, 100);

    expect(fn () => $this->svc->createFromPlan($demand, $this->engine->plan($demand), $this->petugas))
        ->toThrow(InvalidArgumentException::class, 'kosong');

    lgStock($this->gudangA, $this->mie, 'M-1', 500);
    expect(fn () => $this->svc->createFromPlan($demand, $this->engine->plan($other), $this->petugas))
        ->toThrow(InvalidArgumentException::class, 'bukan untuk permintaan ini');
});

it('mengirim: stok gudang berkurang, reservasi terpakai, status dan sopir tercatat', function () {
    [, $dispatches] = newDraft($this);
    $dispatch = $dispatches->first();

    $sent = $this->svc->send($dispatch, $this->petugas, 'Budi', 'N 1234 AB', $this->diPosko);

    expect($sent->status)->toBe(DispatchStatus::Dispatched)
        ->and($sent->driver_name)->toBe('Budi')
        ->and($sent->departure_time)->not->toBeNull()
        ->and($sent->dispatched_by)->toBe($this->petugas->id);

    $inv = DB::table('warehouse_inventories')->where('batch_number', 'A-1')->first();
    expect((float) $inv->quantity_available)->toBe(0.0)->and((float) $inv->quantity_reserved)->toBe(0.0);
});

it('tidak bisa mengirim dua kali atau mengirim pengiriman yang sudah dibatalkan', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();

    $this->svc->send($d, $this->petugas);
    expect(fn () => $this->svc->send($d, $this->petugas))->toThrow(DispatchStateException::class, 'dispatched');

    $other = $dispatches->last();
    $this->svc->cancel($other, $this->petugas, 'salah gudang');
    expect(fn () => $this->svc->send($other, $this->petugas))->toThrow(DispatchStateException::class, 'cancelled');

    // Stok keluar tepat sekali.
    expect((float) DB::table('warehouse_inventories')->where('batch_number', 'A-1')->value('quantity_available'))->toBe(0.0);
});

it('membatalkan draft: reservasi dilepas dan stok bebas kembali', function () {
    [$demand, $dispatches] = newDraft($this);

    foreach ($dispatches as $d) {
        $this->svc->cancel($d, $this->petugas, 'permintaan dibatalkan');
    }

    expect((float) DB::table('warehouse_inventories')->sum('quantity_reserved'))->toBe(0.0)
        ->and($dispatches->first()->refresh()->status)->toBe(DispatchStatus::Cancelled)
        // Kebutuhan kembali utuh karena pengiriman yang dibatalkan tidak dihitung dalam perjalanan.
        ->and($this->engine->outstanding($demand->fresh()))->toBe('300.00');
});

it('tidak bisa membatalkan pengiriman yang sudah berangkat', function () {
    [, $dispatches] = newDraft($this);
    $this->svc->send($dispatches->first(), $this->petugas);

    expect(fn () => $this->svc->cancel($dispatches->first(), $this->petugas, 'terlambat'))
        ->toThrow(DispatchStateException::class);
});

it('menerima di posko: stok posko bertambah, permintaan terpenuhi, status received', function () {
    [$demand, $dispatches] = newDraft($this);

    foreach ($dispatches as $d) {
        $this->svc->send($d, $this->petugas);
        $this->svc->receive($d, $this->koordinator, $this->diPosko, notes: 'lengkap');
    }

    expect((float) DB::table('camp_inventories')->where('camp_id', $this->posko->id)->value('quantity_on_hand'))->toBe(300.0)
        ->and($demand->refresh()->status)->toBe(DemandStatus::Fulfilled)
        ->and($demand->quantity_fulfilled)->toBe(300.0)
        ->and($dispatches->first()->refresh()->status)->toBe(DispatchStatus::Received)
        ->and($dispatches->first()->received_by)->toBe($this->koordinator->id);
});

it('menandai permintaan sebagian terpenuhi sampai semua pengiriman diterima', function () {
    [$demand, $dispatches] = newDraft($this);

    $first = $dispatches->first();
    $this->svc->send($first, $this->petugas);
    $this->svc->receive($first, $this->koordinator, $this->diPosko);

    expect($demand->refresh()->status)->toBe(DemandStatus::Partial)
        ->and($demand->quantity_fulfilled)->toBe(200.0);
});

it('menolak serah terima di luar geofence dan tidak mengubah apa pun', function () {
    [$demand, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas);

    try {
        $this->svc->receive($d, $this->koordinator, $this->satuKm);
        $this->fail('Seharusnya ditolak');
    } catch (GeofenceViolationException $e) {
        expect($e->distanceMeters)->toBeGreaterThan(900)->and($e->getMessage())->toContain('500 m');
    }

    expect($d->refresh()->status)->toBe(DispatchStatus::Dispatched)
        ->and(DB::table('camp_inventories')->count())->toBe(0)
        ->and($demand->refresh()->quantity_fulfilled)->toBe(0.0);
});

it('menerima GPS yang melenceng wajar karena akurasi perangkat, tapi menolak akurasi yang dibuat-buat', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas);

    // ~560 m dari posko dengan akurasi 100 m → jarak efektif ~460 m, lolos.
    $dekat = ['lat' => -8.1050, 'lng' => 113.2000, 'accuracy' => 100.0];
    // ~1 km dengan akurasi 5000 m: dibatasi 100 m, jarak efektif tetap ~900 m, ditolak.
    $palsu = ['lat' => -8.1090, 'lng' => 113.2000, 'accuracy' => 5000.0];

    expect(fn () => $this->svc->receive($d, $this->koordinator, $palsu))->toThrow(GeofenceViolationException::class);

    $this->svc->receive($d, $this->koordinator, $dekat);
    expect($d->refresh()->status)->toBe(DispatchStatus::Received);
});

it('mengizinkan override geofence hanya bagi yang berhak dan dengan alasan, lalu mencatatnya', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas);

    // Koordinator biasa tidak berhak, walau memberi alasan.
    expect(fn () => $this->svc->receive($d, $this->koordinator, $this->satuKm, geofenceOverrideReason: 'GPS rusak'))
        ->toThrow(GeofenceViolationException::class);

    // Operator berhak, tapi tanpa alasan tetap ditolak.
    expect(fn () => $this->svc->receive($d, $this->operator, $this->satuKm))->toThrow(GeofenceViolationException::class);
    expect(fn () => $this->svc->receive($d, $this->operator, $this->satuKm, geofenceOverrideReason: '  '))->toThrow(GeofenceViolationException::class);

    $this->svc->receive($d, $this->operator, $this->satuKm, geofenceOverrideReason: 'Posko pindah tenda, GPS rusak');

    $log = DB::table('logistics_audit_logs')->where('action', 'DISPATCH_RECEIVED')->first();
    $new = json_decode($log->new_values, true);

    expect($new['geofence_overridden'])->toBeTrue()
        ->and($new['geofence_override_reason'])->toBe('Posko pindah tenda, GPS rusak')
        ->and($new['distance_to_camp_m'])->toBeGreaterThan(900);
});

it('mencatat selisih saat jumlah diterima kurang dari yang dikirim', function () {
    [$demand, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas);
    $lineId = $d->items()->first()->id;

    $this->svc->receive($d, $this->koordinator, $this->diPosko, [$lineId => 150]);

    $new = json_decode(DB::table('logistics_audit_logs')->where('action', 'DISPATCH_RECEIVED')->value('new_values'), true);

    expect((float) DB::table('camp_inventories')->value('quantity_on_hand'))->toBe(150.0)
        ->and($new['shortfalls'][0])->toMatchArray(['dispatched' => '200.00', 'received' => '150.00'])
        ->and($demand->refresh()->quantity_fulfilled)->toBe(150.0);
});

it('menolak jumlah diterima yang melebihi jumlah dikirim atau item yang bukan miliknya', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas);
    $lineId = $d->items()->first()->id;

    expect(fn () => $this->svc->receive($d, $this->koordinator, $this->diPosko, [$lineId => 999]))
        ->toThrow(InvalidArgumentException::class, 'melebihi');
    expect(fn () => $this->svc->receive($d, $this->koordinator, $this->diPosko, ['bukan-item' => 1]))
        ->toThrow(InvalidArgumentException::class, 'tidak dikenal');

    expect($d->refresh()->status)->toBe(DispatchStatus::Dispatched);
});

it('tidak bisa menerima dua kali atau menerima draft', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();

    expect(fn () => $this->svc->receive($d, $this->koordinator, $this->diPosko))->toThrow(DispatchStateException::class, 'draft');

    $this->svc->send($d, $this->petugas);
    $this->svc->receive($d, $this->koordinator, $this->diPosko);

    expect(fn () => $this->svc->receive($d, $this->koordinator, $this->diPosko))->toThrow(DispatchStateException::class, 'received');
    expect((float) DB::table('camp_inventories')->value('quantity_on_hand'))->toBe(200.0);
});

it('menerima pengiriman yang ditandai dalam perjalanan', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas);
    $this->svc->markInTransit($d, $this->petugas);

    $this->svc->receive($d, $this->koordinator, $this->diPosko);

    expect($d->refresh()->status)->toBe(DispatchStatus::Received);
});

it('menyisakan jejak audit lengkap dengan rantai yang utuh sepanjang alur', function () {
    [, $dispatches] = newDraft($this);
    $d = $dispatches->first();
    $this->svc->send($d, $this->petugas, geo: $this->diPosko);
    $this->svc->receive($d, $this->koordinator, $this->diPosko);

    $actions = DB::table('logistics_audit_logs')->pluck('action')->unique()->values()->all();

    expect($actions)->toContain('DISPATCH_CREATED', 'STOCK_RESERVED', 'STOCK_DISPATCHED_OUT', 'DISPATCH_SENT', 'STOCK_RECEIVED_AT_CAMP', 'DISPATCH_RECEIVED')
        ->and(app(AuditLogger::class)->verify())->toBeNull();

    // Serah terima menyimpan siapa, dari IP mana, dan di koordinat mana.
    $row = DB::table('logistics_audit_logs')->selectRaw('user_id, geo_accuracy_m, ST_X(geo_point) lng')->where('action', 'DISPATCH_RECEIVED')->first();
    expect($row->user_id)->toBe($this->koordinator->id)
        ->and((float) $row->lng)->toEqualWithDelta(113.2, 0.0001)
        ->and((float) $row->geo_accuracy_m)->toBe(10.0);
});
