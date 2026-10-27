<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class LogisticsSummaryController extends Controller
{
    /** Angka ringkas untuk bagian atas konsol logistik. */
    public function __invoke(): JsonResponse
    {
        abort_unless(auth()->user()->can('access-logistics'), 403);

        $today = now()->toDateString();
        $soon = now()->addDays(14)->toDateString();

        return response()->json([
            'demands_open' => DB::table('camp_demands')->whereIn('status', ['pending', 'partial'])->count(),
            'demands_critical' => DB::table('camp_demands')->whereIn('status', ['pending', 'partial'])->where('urgency_level', 'critical')->count(),
            'dispatches_active' => DB::table('relief_dispatches')->whereIn('status', ['draft', 'dispatched', 'in_transit'])->count(),
            // Batch yang masih punya stok bebas tetapi segera atau sudah lewat kedaluwarsa.
            'batches_expiring' => DB::table('warehouse_inventories')
                ->whereRaw('quantity_available - quantity_reserved > 0')
                ->whereBetween('expiry_date', [$today, $soon])->count(),
            'batches_expired' => DB::table('warehouse_inventories')
                ->whereRaw('quantity_available > 0')
                ->where('expiry_date', '<', $today)->count(),
        ]);
    }
}
