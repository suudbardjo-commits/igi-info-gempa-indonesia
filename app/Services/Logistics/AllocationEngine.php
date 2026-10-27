<?php

namespace App\Services\Logistics;

use App\Enums\DemandStatus;
use App\Models\CampDemand;
use Illuminate\Support\Facades\DB;

/**
 * Mencocokkan kebutuhan posko dengan stok gudang.
 *
 * Aturan (berurutan):
 *  1. Hanya stok bebas (tersedia dikurangi reservasi). Barang mudah rusak yang kedaluwarsanya
 *     lebih dekat dari `min_shelf_life_days` dilewati.
 *  2. Gudang diurutkan dari yang terdekat ke posko (jarak garis lurus di atas bola bumi;
 *     ini bukan rute jalan).
 *  3. Di dalam satu gudang, batch yang lebih cepat kedaluwarsa dipakai lebih dulu (FEFO).
 *  4. Kebutuhan yang kurang diambil dari gudang berikutnya, paling banyak `max_sources` gudang.
 *  5. Kebutuhan dikurangi barang yang sudah dalam perjalanan atau direservasi untuk permintaan yang sama.
 *
 * Hanya menghitung usulan; tidak mengubah stok.
 */
class AllocationEngine
{
    public function plan(CampDemand $demand): AllocationPlan
    {
        $demand->loadMissing('item', 'camp');

        $outstanding = $this->outstanding($demand);

        if (bccomp($outstanding, '0', 2) <= 0 || in_array($demand->status, [DemandStatus::Cancelled, DemandStatus::Fulfilled], true)) {
            return new AllocationPlan($demand->id, $demand->item_id, '0.00', '0.00', '0.00', []);
        }

        $candidates = $this->candidates($demand);

        $remaining = $outstanding;
        $sources = [];
        $maxSources = (int) config('disaster.logistics.max_sources');

        foreach ($candidates as $row) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $isNewWarehouse = ! isset($sources[$row->warehouse_id]);

            if ($isNewWarehouse && count($sources) >= $maxSources) {
                continue;
            }

            $take = bccomp($row->free, $remaining, 2) < 0 ? $row->free : $remaining;

            $sources[$row->warehouse_id] ??= [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_code' => $row->code,
                'warehouse_name' => $row->name,
                'distance_km' => $row->distance_km,
                'quantity' => '0.00',
                'lines' => [],
            ];

            $sources[$row->warehouse_id]['lines'][] = [
                'inventory_id' => $row->inventory_id,
                'batch_number' => $row->batch_number,
                'expiry_date' => $row->expiry_date,
                'quantity' => $take,
            ];
            $sources[$row->warehouse_id]['quantity'] = bcadd($sources[$row->warehouse_id]['quantity'], $take, 2);

            $remaining = bcsub($remaining, $take, 2);
        }

        return new AllocationPlan(
            $demand->id,
            $demand->item_id,
            $outstanding,
            bcsub($outstanding, $remaining, 2),
            $remaining,
            array_values($sources),
        );
    }

    /** Sisa kebutuhan setelah dikurangi yang sudah terpenuhi dan yang sedang dikirim. */
    public function outstanding(CampDemand $demand): string
    {
        $inFlight = (string) DB::table('relief_dispatch_items as di')
            ->join('relief_dispatches as d', 'd.id', '=', 'di.dispatch_id')
            ->where('di.demand_id', $demand->id)
            ->whereIn('d.status', ['draft', 'dispatched', 'in_transit'])
            ->sum('di.quantity_dispatched');

        $left = bcsub(bcsub(number_format($demand->quantity_requested, 2, '.', ''), number_format($demand->quantity_fulfilled, 2, '.', ''), 2), bcadd($inFlight, '0', 2), 2);

        return bccomp($left, '0', 2) < 0 ? '0.00' : $left;
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function candidates(CampDemand $demand)
    {
        $item = $demand->item;

        $minExpiry = $item->is_perishable
            ? now()->addDays((int) config('disaster.logistics.min_shelf_life_days'))->toDateString()
            : null;

        return collect(DB::select(
            'SELECT w.id AS warehouse_id, w.code, w.name,
                    ROUND((ST_Distance(w.coordinates::geography, c.coordinates::geography) / 1000)::numeric, 2)::text AS distance_km,
                    wi.id AS inventory_id, wi.batch_number, wi.expiry_date::text AS expiry_date,
                    (wi.quantity_available - wi.quantity_reserved)::text AS free,
                    ST_Distance(w.coordinates::geography, c.coordinates::geography) AS dist_m
               FROM relief_warehouses w
               JOIN warehouse_inventories wi ON wi.warehouse_id = w.id AND wi.item_id = ?
               JOIN evacuation_camps c ON c.id = ?
              WHERE wi.quantity_available - wi.quantity_reserved > 0
                AND (?::date IS NULL OR wi.expiry_date IS NULL OR wi.expiry_date >= ?::date)
              ORDER BY dist_m ASC, w.id ASC, wi.expiry_date ASC NULLS LAST, wi.batch_number ASC',
            [$demand->item_id, $demand->camp_id, $minExpiry, $minExpiry],
        ));
    }
}
