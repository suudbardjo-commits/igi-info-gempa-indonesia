<?php

namespace App\Services\Logistics;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\EvacuationCamp;
use App\Models\Item;
use App\Models\ReliefDispatch;
use App\Models\ReliefWarehouse;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Support\Quantity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Satu-satunya pintu untuk mengubah stok. Setiap perubahan:
 *  - dilakukan dengan UPDATE bersyarat yang atomik di database (tanpa baca-lalu-tulis),
 *    jadi dua permintaan serentak tidak bisa sama-sama mengambil stok terakhir,
 *  - dicatat sebagai baris ledger (stock_movements) dan baris audit,
 *  - berjalan dalam satu transaksi dengan pemanggilnya.
 */
class InventoryLedgerService
{
    public function __construct(private AuditLogger $audit) {}

    /** Stok masuk ke gudang. Batch yang sama ditambahkan ke baris yang sudah ada. */
    public function receiveIntoWarehouse(
        ReliefWarehouse $warehouse,
        Item $item,
        string $batchNumber,
        float|int|string $quantity,
        ?CarbonInterface $expiryDate = null,
        ?User $user = null,
    ): WarehouseInventory {
        $qty = Quantity::positive($quantity);

        return DB::transaction(function () use ($warehouse, $item, $batchNumber, $qty, $expiryDate, $user) {
            // Satu nomor batch hanya boleh punya satu tanggal kedaluwarsa; kalau beda, kemungkinan salah input
            // dan FEFO akan salah mengurutkan.
            $existing = DB::table('warehouse_inventories')
                ->where(['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'batch_number' => $batchNumber])
                ->value('expiry_date');

            if ($existing !== null && $expiryDate !== null && $existing !== $expiryDate->toDateString()) {
                throw new InvalidArgumentException("Batch {$batchNumber} sudah tercatat dengan kedaluwarsa {$existing}.");
            }

            // Upsert atomik: batch baru dibuat, batch lama ditambah.
            $row = DB::selectOne(
                'INSERT INTO warehouse_inventories
                    (id, warehouse_id, item_id, batch_number, quantity_available, quantity_reserved, expiry_date, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, now(), now())
                 ON CONFLICT (warehouse_id, item_id, batch_number) DO UPDATE
                    SET quantity_available = warehouse_inventories.quantity_available + EXCLUDED.quantity_available,
                        updated_at = now()
                 RETURNING id, quantity_available',
                [(string) Str::uuid7(), $warehouse->id, $item->id, $batchNumber, $qty, $expiryDate?->toDateString()],
            );

            $this->movement(StockMovementType::WarehouseIn, $item->id, $qty, $user, inventoryId: $row->id);

            $this->audit->record(
                'INVENTORY_IN', 'warehouse_inventory', $row->id,
                null, ['warehouse' => $warehouse->code, 'item' => $item->sku, 'batch' => $batchNumber, 'qty_in' => $qty, 'qty_available' => $row->quantity_available],
                $user, request()?->ip(),
            );

            return WarehouseInventory::findOrFail($row->id);
        });
    }

    /** Sisihkan stok bebas untuk satu pengiriman (draft). */
    public function reserve(WarehouseInventory $inventory, float|int|string $quantity, ReliefDispatch $dispatch, ?User $user = null): void
    {
        $qty = Quantity::positive($quantity);

        DB::transaction(function () use ($inventory, $qty, $dispatch, $user) {
            $row = DB::selectOne(
                'UPDATE warehouse_inventories
                    SET quantity_reserved = quantity_reserved + ?, updated_at = now()
                  WHERE id = ? AND quantity_available - quantity_reserved >= ?
              RETURNING quantity_available, quantity_reserved',
                [$qty, $inventory->id, $qty],
            );

            if (! $row) {
                $free = DB::table('warehouse_inventories')->where('id', $inventory->id)
                    ->selectRaw('quantity_available - quantity_reserved AS free')->value('free') ?? '0.00';

                throw InsufficientStockException::forReservation($qty, (string) $free);
            }

            $this->movement(StockMovementType::Reserve, $inventory->item_id, $qty, $user, $inventory->id, dispatch: $dispatch);
            $this->auditStock('STOCK_RESERVED', $inventory, $qty, $row, $user);
        });
    }

    /** Lepas reservasi (pengiriman dibatalkan). */
    public function releaseReservation(WarehouseInventory $inventory, float|int|string $quantity, ReliefDispatch $dispatch, ?User $user = null): void
    {
        $qty = Quantity::positive($quantity);

        DB::transaction(function () use ($inventory, $qty, $dispatch, $user) {
            $row = DB::selectOne(
                'UPDATE warehouse_inventories
                    SET quantity_reserved = quantity_reserved - ?, updated_at = now()
                  WHERE id = ? AND quantity_reserved >= ?
              RETURNING quantity_available, quantity_reserved',
                [$qty, $inventory->id, $qty],
            );

            if (! $row) {
                throw InsufficientStockException::forDispatch($qty);
            }

            $this->movement(StockMovementType::ReleaseReservation, $inventory->item_id, $qty, $user, $inventory->id, dispatch: $dispatch);
            $this->auditStock('STOCK_RESERVATION_RELEASED', $inventory, $qty, $row, $user);
        });
    }

    /** Barang meninggalkan gudang: mengurangi stok sekaligus memakai reservasinya. */
    public function dispatchOut(WarehouseInventory $inventory, float|int|string $quantity, ReliefDispatch $dispatch, ?User $user = null, ?array $geo = null): void
    {
        $qty = Quantity::positive($quantity);

        DB::transaction(function () use ($inventory, $qty, $dispatch, $user, $geo) {
            $row = DB::selectOne(
                'UPDATE warehouse_inventories
                    SET quantity_available = quantity_available - ?,
                        quantity_reserved = quantity_reserved - ?,
                        updated_at = now()
                  WHERE id = ? AND quantity_reserved >= ?
              RETURNING quantity_available, quantity_reserved',
                [$qty, $qty, $inventory->id, $qty],
            );

            if (! $row) {
                throw InsufficientStockException::forDispatch($qty);
            }

            $this->movement(StockMovementType::DispatchOut, $inventory->item_id, bcmul($qty, '-1', 2), $user, $inventory->id, dispatch: $dispatch);
            $this->auditStock('STOCK_DISPATCHED_OUT', $inventory, $qty, $row, $user, $geo);
        });
    }

    /** Barang diterima di posko. */
    public function receiveIntoCamp(EvacuationCamp $camp, Item $item, float|int|string $quantity, ReliefDispatch $dispatch, ?User $user = null, ?array $geo = null): void
    {
        $qty = Quantity::positive($quantity);

        DB::transaction(function () use ($camp, $item, $qty, $dispatch, $user, $geo) {
            $row = DB::selectOne(
                'INSERT INTO camp_inventories (id, camp_id, item_id, quantity_on_hand, created_at, updated_at)
                 VALUES (?, ?, ?, ?, now(), now())
                 ON CONFLICT (camp_id, item_id) DO UPDATE
                    SET quantity_on_hand = camp_inventories.quantity_on_hand + EXCLUDED.quantity_on_hand,
                        updated_at = now()
                 RETURNING id, quantity_on_hand',
                [(string) Str::uuid7(), $camp->id, $item->id, $qty],
            );

            $this->movement(StockMovementType::CampIn, $item->id, $qty, $user, dispatch: $dispatch, campId: $camp->id);

            $this->audit->record(
                'STOCK_RECEIVED_AT_CAMP', 'camp_inventory', $row->id,
                null, ['camp' => $camp->code, 'item' => $item->sku, 'qty_in' => $qty, 'qty_on_hand' => $row->quantity_on_hand, 'dispatch' => $dispatch->dispatch_code],
                $user, request()?->ip(), $geo,
            );
        });
    }

    private function movement(
        StockMovementType $type,
        string $itemId,
        string $quantity,
        ?User $user,
        ?string $inventoryId = null,
        ?ReliefDispatch $dispatch = null,
        ?string $campId = null,
    ): void {
        StockMovement::create([
            'type' => $type,
            'item_id' => $itemId,
            'warehouse_inventory_id' => $inventoryId,
            'camp_id' => $campId,
            'dispatch_id' => $dispatch?->id,
            'quantity' => $quantity,
            'user_id' => $user?->id,
        ]);
    }

    private function auditStock(string $action, WarehouseInventory $inv, string $qty, object $after, ?User $user, ?array $geo = null): void
    {
        $this->audit->record(
            $action, 'warehouse_inventory', $inv->id,
            null,
            ['qty' => $qty, 'qty_available' => $after->quantity_available, 'qty_reserved' => $after->quantity_reserved, 'batch' => $inv->batch_number],
            $user, request()?->ip(), $geo,
        );
    }
}
