<?php

use App\Enums\DispatchStatus;
use App\Models\EvacuationCamp;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ReliefDispatch;
use App\Models\ReliefWarehouse;
use App\Models\User;
use App\Models\WarehouseInventory;
use App\Services\Logistics\InventoryLedgerService;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** Helper untuk tes logistik; awalan `lg` menghindari bentrok dengan helper tes lain. */
function lgWarehouse(string $code, float $lng, float $lat): ReliefWarehouse
{
    return ReliefWarehouse::create([
        'name' => "Gudang {$code}", 'code' => $code, 'capacity_m3' => 1000, 'contact_phone' => '0',
        'coordinates' => ReliefWarehouse::makePoint($lng, $lat),
    ]);
}

function lgCamp(string $code, float $lng, float $lat, int $capacity = 200): EvacuationCamp
{
    return EvacuationCamp::create([
        'name' => "Posko {$code}", 'code' => $code, 'capacity_people' => $capacity, 'contact_phone' => '0',
        'coordinates' => EvacuationCamp::makePoint($lng, $lat),
    ]);
}

function lgItem(string $sku = 'BERAS', bool $perishable = true): Item
{
    $category = ItemCategory::firstOrCreate(['slug' => 'pangan'], ['name' => 'Pangan']);

    return Item::firstOrCreate(['sku' => $sku], [
        'category_id' => $category->id, 'name' => ucfirst(strtolower($sku)), 'unit' => 'kg', 'is_perishable' => $perishable,
    ]);
}

function lgStock(ReliefWarehouse $w, Item $item, string $batch, float|int|string $qty, ?CarbonInterface $expiry = null, ?User $user = null): WarehouseInventory
{
    return app(InventoryLedgerService::class)->receiveIntoWarehouse($w, $item, $batch, $qty, $expiry, $user);
}

/** Gempa untuk tes alert; secara bawaan segar (5 menit lalu). */
function lgQuake(float $mag, float $lng, float $lat, array $extra = []): App\Models\DisasterEvent
{
    return App\Models\DisasterEvent::create(array_merge([
        'external_id' => 'T-'.Str::uuid(),
        'source' => 'bmkg',
        'type' => 'earthquake',
        'magnitude' => $mag,
        'depth_km' => 15,
        'location_name' => 'Uji gempa',
        'tsunami_potential' => 'Tidak berpotensi tsunami',
        'impact_radius_km' => App\Services\DisasterIngestion\ImpactRadius::forMagnitude($mag),
        'is_verified' => true,
        'occurred_at' => now()->subMinutes(5),
        'coordinates' => App\Models\DisasterEvent::makePoint($lng, $lat),
    ], $extra));
}

function lgLinkTelegram(User $user, int $chatId): App\Models\TelegramLink
{
    return App\Models\TelegramLink::create(['user_id' => $user->id, 'chat_id' => $chatId, 'linked_at' => now()]);
}

function lgDemand(EvacuationCamp $camp, Item $item, float|int $qty, string $urgency = 'high'): App\Models\CampDemand
{
    return App\Models\CampDemand::create([
        'camp_id' => $camp->id, 'item_id' => $item->id, 'quantity_requested' => $qty, 'urgency_level' => $urgency,
    ]);
}

function lgDispatch(ReliefWarehouse $w, EvacuationCamp $c, DispatchStatus $status = DispatchStatus::Draft): ReliefDispatch
{
    return ReliefDispatch::create([
        'dispatch_code' => 'DSP-'.Str::upper(Str::random(8)),
        'source_warehouse_id' => $w->id,
        'destination_camp_id' => $c->id,
        'status' => $status,
    ]);
}
