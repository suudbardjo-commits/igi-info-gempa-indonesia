<?php

namespace App\Services\Logistics;

use App\Enums\DemandStatus;
use App\Enums\DispatchStatus;
use App\Exceptions\DispatchStateException;
use App\Exceptions\GeofenceViolationException;
use App\Models\CampDemand;
use App\Models\ReliefDispatch;
use App\Models\ReliefDispatchItem;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Siklus pengiriman bantuan:
 *
 *   draft ──kirim──▶ dispatched ──(opsional)──▶ in_transit ──terima──▶ received
 *     └──batal──▶ cancelled
 *
 * Setiap langkah berjalan dalam satu transaksi: kalau satu bagian gagal, stok, ledger, dan
 * audit kembali seperti semula.
 */
class DispatchService
{
    public function __construct(
        private InventoryLedgerService $ledger,
        private AuditLogger $audit,
    ) {}

    /**
     * Buat pengiriman draft (satu per gudang sumber) dari sebuah rencana alokasi dan reservasi stoknya.
     * Semua atau tidak sama sekali: kalau stok sudah berubah sejak rencana dihitung, tidak ada yang dibuat.
     *
     * @return Collection<int, ReliefDispatch>
     */
    public function createFromPlan(CampDemand $demand, AllocationPlan $plan, ?User $user = null): Collection
    {
        if ($plan->isEmpty()) {
            throw new InvalidArgumentException('Rencana alokasi kosong: tidak ada stok yang bisa dikirim.');
        }

        if ($plan->demandId !== $demand->id) {
            throw new InvalidArgumentException('Rencana alokasi bukan untuk permintaan ini.');
        }

        return DB::transaction(function () use ($demand, $plan, $user) {
            $dispatches = collect();

            foreach ($plan->sources as $source) {
                $dispatch = ReliefDispatch::create([
                    'dispatch_code' => $this->newCode(),
                    'source_warehouse_id' => $source['warehouse_id'],
                    'destination_camp_id' => $demand->camp_id,
                    'status' => DispatchStatus::Draft,
                ]);

                foreach ($source['lines'] as $line) {
                    $inventory = WarehouseInventory::findOrFail($line['inventory_id']);

                    // Bisa melempar InsufficientStockException; seluruh transaksi dibatalkan.
                    $this->ledger->reserve($inventory, $line['quantity'], $dispatch, $user);

                    ReliefDispatchItem::create([
                        'dispatch_id' => $dispatch->id,
                        'inventory_id' => $inventory->id,
                        'item_id' => $inventory->item_id,
                        'demand_id' => $demand->id,
                        'quantity_dispatched' => $line['quantity'],
                    ]);
                }

                $this->audit->record(
                    'DISPATCH_CREATED', 'relief_dispatch', $dispatch->id,
                    null,
                    ['code' => $dispatch->dispatch_code, 'warehouse' => $source['warehouse_code'], 'camp' => $demand->camp->code, 'quantity' => $source['quantity']],
                    $user, request()?->ip(),
                );

                $dispatches->push($dispatch);
            }

            return $dispatches;
        });
    }

    /**
     * Barang berangkat dari gudang: stok gudang berkurang, pengiriman berstatus "dispatched".
     *
     * @param  array{lat: float, lng: float, accuracy?: float|null}|null  $geo
     */
    public function send(ReliefDispatch $dispatch, User $user, ?string $driverName = null, ?string $vehiclePlate = null, ?array $geo = null): ReliefDispatch
    {
        return DB::transaction(function () use ($dispatch, $user, $driverName, $vehiclePlate, $geo) {
            $dispatch = $this->lock($dispatch);

            if ($dispatch->status !== DispatchStatus::Draft) {
                throw DispatchStateException::cannot('dikirim', $dispatch->status->value);
            }

            foreach ($dispatch->items()->with('inventory')->get() as $item) {
                $this->ledger->dispatchOut($item->inventory, $item->quantity_dispatched, $dispatch, $user, $geo);
            }

            $dispatch->update([
                'status' => DispatchStatus::Dispatched,
                'driver_name' => $driverName,
                'vehicle_plate_number' => $vehiclePlate,
                'departure_time' => now(),
                'dispatched_by' => $user->id,
            ]);

            $this->audit->record(
                'DISPATCH_SENT', 'relief_dispatch', $dispatch->id,
                ['status' => 'draft'],
                ['status' => 'dispatched', 'driver' => $driverName, 'vehicle' => $vehiclePlate],
                $user, request()?->ip(), $geo,
            );

            return $dispatch->refresh();
        });
    }

    public function markInTransit(ReliefDispatch $dispatch, User $user): ReliefDispatch
    {
        return DB::transaction(function () use ($dispatch, $user) {
            $dispatch = $this->lock($dispatch);

            if ($dispatch->status !== DispatchStatus::Dispatched) {
                throw DispatchStateException::cannot('ditandai dalam perjalanan', $dispatch->status->value);
            }

            $dispatch->update(['status' => DispatchStatus::InTransit]);

            $this->audit->record(
                'DISPATCH_IN_TRANSIT', 'relief_dispatch', $dispatch->id,
                ['status' => 'dispatched'], ['status' => 'in_transit'], $user, request()?->ip(),
            );

            return $dispatch->refresh();
        });
    }

    /**
     * Batalkan pengiriman yang belum berangkat dan lepas reservasinya.
     */
    public function cancel(ReliefDispatch $dispatch, User $user, string $reason): ReliefDispatch
    {
        return DB::transaction(function () use ($dispatch, $user, $reason) {
            $dispatch = $this->lock($dispatch);

            if ($dispatch->status !== DispatchStatus::Draft) {
                throw DispatchStateException::cannot('dibatalkan', $dispatch->status->value);
            }

            foreach ($dispatch->items()->with('inventory')->get() as $item) {
                $this->ledger->releaseReservation($item->inventory, $item->quantity_dispatched, $dispatch, $user);
            }

            $dispatch->update(['status' => DispatchStatus::Cancelled]);

            $this->audit->record(
                'DISPATCH_CANCELLED', 'relief_dispatch', $dispatch->id,
                ['status' => 'draft'], ['status' => 'cancelled', 'reason' => $reason], $user, request()?->ip(),
            );

            return $dispatch->refresh();
        });
    }

    /**
     * Posko menerima barang. Koordinat GPS penerima harus berada di dekat posko (geofence).
     *
     * @param  array{lat: float, lng: float, accuracy?: float|null}  $geo  lokasi perangkat penerima
     * @param  array<string, float|int|string>  $received  jumlah diterima per id item pengiriman; kosong berarti semuanya sesuai kiriman
     * @param  string|null  $geofenceOverrideReason  alasan melewati geofence; hanya berlaku bagi pengguna berhak
     */
    public function receive(
        ReliefDispatch $dispatch,
        User $user,
        array $geo,
        array $received = [],
        ?string $notes = null,
        ?string $geofenceOverrideReason = null,
        ?\Carbon\CarbonInterface $arrivedAt = null,
    ): ReliefDispatch {
        return DB::transaction(function () use ($dispatch, $user, $geo, $received, $notes, $geofenceOverrideReason, $arrivedAt) {
            $dispatch = $this->lock($dispatch);

            if (! in_array($dispatch->status, [DispatchStatus::Dispatched, DispatchStatus::InTransit], true)) {
                throw DispatchStateException::cannot('diterima', $dispatch->status->value);
            }

            $previousStatus = $dispatch->status->value;
            $camp = $dispatch->camp;
            $distance = $this->distanceToCamp($camp->id, $geo['lng'], $geo['lat']);
            $overridden = $this->checkGeofence($user, $distance, $geo['accuracy'] ?? null, $geofenceOverrideReason);

            $items = $dispatch->items()->with('item')->get();
            $unknown = array_diff(array_keys($received), $items->pluck('id')->all());

            if ($unknown) {
                throw new InvalidArgumentException('Item pengiriman tidak dikenal: '.implode(', ', $unknown));
            }

            $shortfalls = [];

            foreach ($items as $line) {
                $dispatched = Quantity::positive($line->quantity_dispatched);
                $qty = array_key_exists($line->id, $received) ? Quantity::nonNegative($received[$line->id]) : $dispatched;

                if (bccomp($qty, $dispatched, 2) > 0) {
                    throw new InvalidArgumentException("Jumlah diterima ({$qty}) melebihi jumlah dikirim ({$dispatched}) untuk {$line->item->name}.");
                }

                $line->update(['quantity_received' => $qty]);

                if (bccomp($qty, '0', 2) > 0) {
                    $this->ledger->receiveIntoCamp($camp, $line->item, $qty, $dispatch, $user, $geo);

                    if ($line->demand_id) {
                        $this->applyToDemand($line->demand_id, $qty);
                    }
                }

                if (bccomp($qty, $dispatched, 2) < 0) {
                    $shortfalls[] = ['item' => $line->item->sku, 'dispatched' => $dispatched, 'received' => $qty];
                }
            }

            $dispatch->update([
                'status' => DispatchStatus::Received,
                // Penerimaan yang dicatat offline memakai waktu saat barang benar-benar tiba, bukan saat dikirim ke server.
                'arrival_time' => $arrivedAt ?? now(),
                'received_by' => $user->id,
                'receiver_notes' => $notes,
            ]);

            $this->audit->record(
                'DISPATCH_RECEIVED', 'relief_dispatch', $dispatch->id,
                ['status' => $previousStatus],
                [
                    'status' => 'received',
                    'distance_to_camp_m' => round($distance, 1),
                    'geofence_overridden' => $overridden,
                    'geofence_override_reason' => $overridden ? $geofenceOverrideReason : null,
                    'shortfalls' => $shortfalls,
                    'notes' => $notes,
                ],
                $user, request()?->ip(), $geo,
            );

            return $dispatch->refresh();
        });
    }

    /** Jarak (meter) dari titik GPS ke posko, dihitung di atas bola bumi. */
    private function distanceToCamp(string $campId, float $lng, float $lat): float
    {
        return (float) DB::selectOne(
            'SELECT ST_Distance(coordinates::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS d
               FROM evacuation_camps WHERE id = ?',
            [$lng, $lat, $campId],
        )->d;
    }

    /**
     * GPS ponsel di lapangan bisa meleset, jadi akurasi yang dilaporkan perangkat (maksimal 100 m)
     * dikurangkan dari jarak. Ini pencegah penyalahgunaan, bukan jaminan: koordinat dari klien bisa dipalsukan.
     *
     * @return bool true kalau lolos karena override
     */
    private function checkGeofence(User $user, float $distance, ?float $accuracy, ?string $overrideReason): bool
    {
        $allowed = (int) config('disaster.logistics.geofence_radius_m');
        $effective = $distance - min(max($accuracy ?? 0, 0), 100);

        if ($effective <= $allowed) {
            return false;
        }

        if ($overrideReason !== null && trim($overrideReason) !== '' && $user->can('dispatch.override-geofence')) {
            return true;
        }

        throw new GeofenceViolationException($distance, $allowed);
    }

    /** Tambah jumlah terpenuhi pada permintaan dan perbarui statusnya. */
    private function applyToDemand(string $demandId, string $qty): void
    {
        DB::selectOne(
            "UPDATE camp_demands
                SET quantity_fulfilled = quantity_fulfilled + ?,
                    status = CASE WHEN quantity_fulfilled + ? >= quantity_requested THEN ? ELSE ? END,
                    updated_at = now()
              WHERE id = ? AND status <> ?
          RETURNING id",
            [$qty, $qty, DemandStatus::Fulfilled->value, DemandStatus::Partial->value, $demandId, DemandStatus::Cancelled->value],
        );
    }

    private function lock(ReliefDispatch $dispatch): ReliefDispatch
    {
        return ReliefDispatch::query()->lockForUpdate()->findOrFail($dispatch->id);
    }

    private function newCode(): string
    {
        do {
            $code = 'DSP-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (ReliefDispatch::where('dispatch_code', $code)->exists());

        return $code;
    }
}
