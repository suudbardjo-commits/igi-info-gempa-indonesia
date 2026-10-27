<?php

namespace App\Services\Logistics;

use Illuminate\Support\Facades\DB;

/**
 * Membandingkan saldo stok dengan jumlah baris ledger. Selisih berarti ada perubahan stok
 * yang tidak lewat InventoryLedgerService (edit manual, seeder, atau bug).
 */
class LedgerReconciler
{
    /**
     * @return list<array{scope: string, id: string, label: string, field: string, balance: string, ledger: string}>
     */
    public function mismatches(): array
    {
        $found = [];

        $warehouse = DB::select(
            "SELECT wi.id, wi.batch_number, w.code, i.sku,
                    wi.quantity_available AS available, wi.quantity_reserved AS reserved,
                    COALESCE(SUM(m.quantity) FILTER (WHERE m.type IN ('warehouse_in', 'dispatch_out', 'adjustment')), 0) AS exp_available,
                    COALESCE(SUM(m.quantity) FILTER (WHERE m.type = 'reserve'), 0)
                      - COALESCE(SUM(m.quantity) FILTER (WHERE m.type = 'release_reservation'), 0)
                      + COALESCE(SUM(m.quantity) FILTER (WHERE m.type = 'dispatch_out'), 0) AS exp_reserved
               FROM warehouse_inventories wi
               JOIN relief_warehouses w ON w.id = wi.warehouse_id
               JOIN items i ON i.id = wi.item_id
          LEFT JOIN stock_movements m ON m.warehouse_inventory_id = wi.id
           GROUP BY wi.id, w.code, i.sku",
        );

        foreach ($warehouse as $row) {
            $label = "{$row->code} / {$row->sku} / {$row->batch_number}";

            if (bccomp($row->available, $row->exp_available, 2) !== 0) {
                $found[] = ['scope' => 'gudang', 'id' => $row->id, 'label' => $label, 'field' => 'tersedia', 'balance' => $row->available, 'ledger' => bcadd($row->exp_available, '0', 2)];
            }

            if (bccomp($row->reserved, $row->exp_reserved, 2) !== 0) {
                $found[] = ['scope' => 'gudang', 'id' => $row->id, 'label' => $label, 'field' => 'direservasi', 'balance' => $row->reserved, 'ledger' => bcadd($row->exp_reserved, '0', 2)];
            }
        }

        $camps = DB::select(
            "SELECT ci.id, c.code, i.sku, ci.quantity_on_hand AS on_hand,
                    COALESCE((SELECT SUM(m.quantity) FROM stock_movements m
                               WHERE m.type = 'camp_in' AND m.camp_id = ci.camp_id AND m.item_id = ci.item_id), 0) AS expected
               FROM camp_inventories ci
               JOIN evacuation_camps c ON c.id = ci.camp_id
               JOIN items i ON i.id = ci.item_id",
        );

        foreach ($camps as $row) {
            if (bccomp($row->on_hand, $row->expected, 2) !== 0) {
                $found[] = ['scope' => 'posko', 'id' => $row->id, 'label' => "{$row->code} / {$row->sku}", 'field' => 'di tangan', 'balance' => $row->on_hand, 'ledger' => bcadd($row->expected, '0', 2)];
            }
        }

        return $found;
    }
}
