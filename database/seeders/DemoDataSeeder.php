<?php

namespace Database\Seeders;

use App\Models\CampDemand;
use App\Models\EvacuationCamp;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ReliefWarehouse;
use App\Services\Logistics\InventoryLedgerService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data contoh untuk pengembangan: posko dan gudang fiktif di Jawa Timur.
 * Nama dan koordinat hanya untuk uji, bukan data operasional.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $items = $this->seedItems();
        $warehouses = $this->seedWarehouses();
        $camps = $this->seedCamps();

        // Stok awal juga lewat ledger, supaya saldo dan ledger selalu cocok (lihat `ledger:reconcile`).
        $ledger = app(InventoryLedgerService::class);

        foreach ($warehouses as $warehouse) {
            // Aman dijalankan ulang: gudang yang sudah berisi dilewati (ledger menolak batch yang sama dengan
            // tanggal kedaluwarsa acak yang berbeda, dan menambah stok dua kali bukan yang diinginkan).
            if ($warehouse->inventories()->exists()) {
                continue;
            }

            foreach ($items as $item) {
                $ledger->receiveIntoWarehouse(
                    $warehouse,
                    $item,
                    'BATCH-2026-10',
                    random_int(200, 2000),
                    $item->is_perishable ? now()->addMonths(random_int(2, 8)) : null,
                );
            }
        }

        $urgencies = ['low', 'medium', 'high', 'critical'];

        foreach ($camps as $camp) {
            foreach ($items->random(3) as $item) {
                CampDemand::firstOrCreate(
                    ['camp_id' => $camp->id, 'item_id' => $item->id],
                    [
                        'quantity_requested' => random_int(50, 500),
                        'urgency_level' => $urgencies[array_rand($urgencies)],
                    ],
                );
            }
        }
    }

    private function seedItems()
    {
        $catalog = [
            'Pangan' => [['PGN-BERAS', 'Beras', 'kg', true], ['PGN-MIE', 'Mi instan', 'dus', true], ['PGN-AIR', 'Air mineral', 'dus', true]],
            'Kesehatan' => [['KSH-P3K', 'Kotak P3K', 'pcs', false], ['KSH-PARASET', 'Parasetamol', 'strip', true]],
            'Tempat tinggal' => [['TMP-TENDA', 'Tenda keluarga', 'pcs', false], ['TMP-SELIMUT', 'Selimut', 'pcs', false]],
            'Kebersihan' => [['KBR-SABUN', 'Sabun mandi', 'pcs', false]],
        ];

        $items = collect();

        foreach ($catalog as $category => $rows) {
            $cat = ItemCategory::firstOrCreate(['slug' => Str::slug($category)], ['name' => $category]);

            foreach ($rows as [$sku, $name, $unit, $perishable]) {
                $items->push(Item::firstOrCreate(
                    ['sku' => $sku],
                    ['category_id' => $cat->id, 'name' => $name, 'unit' => $unit, 'is_perishable' => $perishable],
                ));
            }
        }

        return $items;
    }

    private function seedWarehouses()
    {
        $rows = [
            ['GDG-REG-SBY', 'Gudang Regional Surabaya', 112.7521, -7.2575],
            ['GDG-REG-MLG', 'Gudang Regional Malang', 112.6326, -7.9666],
            ['GDG-REG-JBR', 'Gudang Regional Jember', 113.6681, -8.1845],
        ];

        return collect($rows)->map(fn ($r) => ReliefWarehouse::firstOrCreate(
            ['code' => $r[0]],
            [
                'name' => $r[1],
                'coordinates' => ReliefWarehouse::makePoint($r[2], $r[3]),
                'capacity_m3' => 5000,
                'contact_phone' => '0310000000',
            ],
        ));
    }

    private function seedCamps()
    {
        $rows = [
            ['PSK-LMJ-01', 'Posko Lumajang 1', 113.2246, -8.1335, 400],
            ['PSK-MLG-01', 'Posko Malang Selatan', 112.6200, -8.1000, 300],
            ['PSK-BWI-01', 'Posko Banyuwangi', 114.3691, -8.2192, 250],
            ['PSK-JBR-01', 'Posko Jember Kota', 113.7000, -8.1700, 350],
            ['PSK-PSR-01', 'Posko Pasuruan', 112.9075, -7.6469, 200],
            ['PSK-BLT-01', 'Posko Blitar', 112.1681, -8.0954, 280],
        ];

        return collect($rows)->map(fn ($r) => EvacuationCamp::firstOrCreate(
            ['code' => $r[0]],
            [
                'name' => $r[1],
                'coordinates' => EvacuationCamp::makePoint($r[2], $r[3]),
                'capacity_people' => $r[4],
                'contact_phone' => '0810000000',
            ],
        ));
    }
}
