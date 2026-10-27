<?php

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\InventoryLedgerService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->ledger = app(InventoryLedgerService::class);
    $this->gudang = lgWarehouse('GDG-A', 112.75, -7.25);
    $this->posko = lgCamp('PSK-A', 113.2, -8.1);
    $this->beras = lgItem('BERAS');
});

function sumMovements(string $itemId, array $types): string
{
    return (string) DB::table('stock_movements')->where('item_id', $itemId)
        ->whereIn('type', array_map(fn ($t) => $t->value, $types))->sum('quantity');
}

it('mencatat stok masuk sebagai batch dan baris ledger', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100, now()->addMonths(3));

    expect($inv->quantity_available)->toBe(100.0)
        ->and($inv->quantity_reserved)->toBe(0.0)
        ->and(DB::table('stock_movements')->where('type', 'warehouse_in')->count())->toBe(1);
});

it('menambah batch yang sama ke baris yang sudah ada, bukan membuat baris baru', function () {
    lgStock($this->gudang, $this->beras, 'B-1', 100);
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 50.5);

    expect($inv->quantity_available)->toBe(150.5)
        ->and(DB::table('warehouse_inventories')->count())->toBe(1);
});

it('menolak batch yang sama dengan tanggal kedaluwarsa berbeda', function () {
    lgStock($this->gudang, $this->beras, 'B-1', 10, now()->addMonths(3));

    expect(fn () => lgStock($this->gudang, $this->beras, 'B-1', 10, now()->addMonths(9)))
        ->toThrow(InvalidArgumentException::class, 'B-1');
});

it('menolak jumlah nol, negatif, atau lebih dari dua desimal', function (mixed $bad) {
    expect(fn () => lgStock($this->gudang, $this->beras, 'B-X', $bad))->toThrow(InvalidArgumentException::class);
})->with([0, -5, '0.00', '1.234', 'abc']);

it('mereservasi stok bebas dan menghitung sisa bebas dengan benar', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);
    $dispatch = lgDispatch($this->gudang, $this->posko);

    $this->ledger->reserve($inv, 60, $dispatch);

    $inv->refresh();
    expect($inv->quantity_available)->toBe(100.0)
        ->and($inv->quantity_reserved)->toBe(60.0)
        ->and($inv->quantity_free)->toBe(40.0);
});

it('menolak reservasi yang melebihi stok bebas, termasuk stok yang sudah direservasi pengiriman lain', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);

    $this->ledger->reserve($inv, 70, lgDispatch($this->gudang, $this->posko));

    expect(fn () => $this->ledger->reserve($inv, 40, lgDispatch($this->gudang, $this->posko)))
        ->toThrow(InsufficientStockException::class, 'tersedia 30.00');

    // Stok tidak berubah akibat percobaan yang ditolak.
    expect($inv->refresh()->quantity_reserved)->toBe(70.0);
});

it('menolak reservasi persis satu sen di atas sisa bebas, menerima yang persis sama', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 10);
    $d = lgDispatch($this->gudang, $this->posko);

    expect(fn () => $this->ledger->reserve($inv, '10.01', $d))->toThrow(InsufficientStockException::class);

    $this->ledger->reserve($inv, '10.00', $d);
    expect($inv->refresh()->quantity_free)->toBe(0.0);
});

it('melepas reservasi dan tidak bisa melepas lebih dari yang direservasi', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);
    $d = lgDispatch($this->gudang, $this->posko);
    $this->ledger->reserve($inv, 30, $d);

    $this->ledger->releaseReservation($inv, 30, $d);
    expect($inv->refresh()->quantity_reserved)->toBe(0.0);

    expect(fn () => $this->ledger->releaseReservation($inv, 1, $d))->toThrow(InsufficientStockException::class);
});

it('mengeluarkan barang: mengurangi stok sekaligus memakai reservasinya', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);
    $d = lgDispatch($this->gudang, $this->posko);
    $this->ledger->reserve($inv, 40, $d);

    $this->ledger->dispatchOut($inv, 40, $d);

    $inv->refresh();
    expect($inv->quantity_available)->toBe(60.0)->and($inv->quantity_reserved)->toBe(0.0);
});

it('menolak mengeluarkan barang yang tidak direservasi', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);

    expect(fn () => $this->ledger->dispatchOut($inv, 10, lgDispatch($this->gudang, $this->posko)))
        ->toThrow(InsufficientStockException::class);

    expect($inv->refresh()->quantity_available)->toBe(100.0);
});

it('menambah stok posko dan mengakumulasinya', function () {
    $d = lgDispatch($this->gudang, $this->posko);

    $this->ledger->receiveIntoCamp($this->posko, $this->beras, 25, $d);
    $this->ledger->receiveIntoCamp($this->posko, $this->beras, '10.50', $d);

    expect((float) DB::table('camp_inventories')->where('camp_id', $this->posko->id)->value('quantity_on_hand'))->toBe(35.5);
});

it('menjaga saldo selalu sama dengan jumlah baris ledger', function () {
    $a = lgStock($this->gudang, $this->beras, 'B-1', 100);
    $b = lgStock($this->gudang, $this->beras, 'B-2', 40.25);
    $d1 = lgDispatch($this->gudang, $this->posko);
    $d2 = lgDispatch($this->gudang, $this->posko);

    $this->ledger->reserve($a, 30, $d1);
    $this->ledger->reserve($b, 15.75, $d2);
    $this->ledger->dispatchOut($a, 30, $d1);
    $this->ledger->releaseReservation($b, 5, $d2);

    $available = (float) DB::table('warehouse_inventories')->sum('quantity_available');
    $reserved = (float) DB::table('warehouse_inventories')->sum('quantity_reserved');

    $in = sumMovements($this->beras->id, [StockMovementType::WarehouseIn]);
    $out = sumMovements($this->beras->id, [StockMovementType::DispatchOut]);       // bernilai negatif
    $res = sumMovements($this->beras->id, [StockMovementType::Reserve]);
    $rel = sumMovements($this->beras->id, [StockMovementType::ReleaseReservation]);

    expect($available)->toBe((float) bcadd($in, $out, 2))                       // 140.25 - 30
        ->and($reserved)->toBe((float) bcsub(bcadd($res, $out, 2), $rel, 2));    // (30+15.75) - 30 - 5
});

it('mencatat setiap perubahan stok ke jejak audit dengan rantai yang utuh', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);
    $d = lgDispatch($this->gudang, $this->posko);
    $this->ledger->reserve($inv, 10, $d);
    $this->ledger->dispatchOut($inv, 10, $d);

    expect(DB::table('logistics_audit_logs')->pluck('action')->all())
        ->toBe(['INVENTORY_IN', 'STOCK_RESERVED', 'STOCK_DISPATCHED_OUT'])
        ->and(app(AuditLogger::class)->verify())->toBeNull();
});

it('membatalkan seluruh perubahan kalau transaksi pemanggil dibatalkan', function () {
    $inv = lgStock($this->gudang, $this->beras, 'B-1', 100);
    $d = lgDispatch($this->gudang, $this->posko);

    try {
        DB::transaction(function () use ($inv, $d) {
            $this->ledger->reserve($inv, 50, $d);
            throw new RuntimeException('gagal di langkah berikutnya');
        });
    } catch (RuntimeException) {
    }

    expect($inv->refresh()->quantity_reserved)->toBe(0.0)
        ->and(DB::table('stock_movements')->where('type', 'reserve')->count())->toBe(0);
});
