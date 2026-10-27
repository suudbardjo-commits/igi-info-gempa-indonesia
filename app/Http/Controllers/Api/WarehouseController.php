<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReliefWarehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    /** Gudang yang boleh dilihat: semua bagi petugas koordinasi, atau yang dikelolanya sendiri. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $warehouses = ReliefWarehouse::query()
            ->withCount('inventories as batches')
            ->when(! $user->can('demand.view-all'), fn ($q) => $q->where('manager_id', $user->id))
            ->orderBy('name')
            ->get()
            ->map(fn (ReliefWarehouse $w) => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => $w->name,
                'batches' => $w->batches,
                'can_manage' => $user->can('manageStock', $w),
            ]);

        return response()->json(['data' => $warehouses]);
    }
}
