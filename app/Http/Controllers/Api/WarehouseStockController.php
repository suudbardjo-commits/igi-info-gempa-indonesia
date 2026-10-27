<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockInRequest;
use App\Models\Item;
use App\Models\ReliefWarehouse;
use App\Services\Logistics\InventoryLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class WarehouseStockController extends Controller
{
    /** Stok per batch di satu gudang, beserta sisa yang masih bebas dialokasikan. */
    public function index(ReliefWarehouse $warehouse): JsonResponse
    {
        $this->authorize('viewStock', $warehouse);

        $rows = $warehouse->inventories()->with('item:id,sku,name,unit')
            ->orderBy('expiry_date')->orderBy('batch_number')->get()
            ->map(fn ($i) => [
                'id' => $i->id,
                'item' => ['sku' => $i->item->sku, 'name' => $i->item->name, 'unit' => $i->item->unit],
                'batch_number' => $i->batch_number,
                'expiry_date' => $i->expiry_date?->toDateString(),
                'quantity_available' => $i->quantity_available,
                'quantity_reserved' => $i->quantity_reserved,
                'quantity_free' => $i->quantity_free,
            ]);

        return response()->json([
            'data' => $rows,
            'can_manage' => request()->user()->can('manageStock', $warehouse),
        ]);
    }

    public function store(StockInRequest $request, ReliefWarehouse $warehouse, InventoryLedgerService $ledger): JsonResponse
    {
        $this->authorize('manageStock', $warehouse);

        $data = $request->validated();

        $inventory = $ledger->receiveIntoWarehouse(
            $warehouse,
            Item::findOrFail($data['item_id']),
            $data['batch_number'],
            $data['quantity'],
            isset($data['expiry_date']) ? CarbonImmutable::parse($data['expiry_date']) : null,
            $request->user(),
        );

        return response()->json([
            'id' => $inventory->id,
            'batch_number' => $inventory->batch_number,
            'quantity_available' => $inventory->quantity_available,
            'quantity_free' => $inventory->quantity_free,
        ], 201);
    }
}
