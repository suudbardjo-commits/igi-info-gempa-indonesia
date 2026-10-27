<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FieldReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldReportController extends Controller
{
    /** Laporan lapangan terbaru untuk petugas koordinasi. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('demand.view-all'), 403);

        $data = $request->validate(['type' => ['nullable', 'in:occupancy,condition'], 'camp_id' => ['nullable', 'uuid']]);

        $page = FieldReport::query()
            ->with(['camp:id,code,name', 'reporter:id,name'])
            ->selectRaw('field_reports.*, ST_X(geo_point::geometry) AS lng, ST_Y(geo_point::geometry) AS lat')
            ->when(isset($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->when(isset($data['camp_id']), fn ($q) => $q->where('camp_id', $data['camp_id']))
            ->orderByDesc('recorded_at')
            ->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn (FieldReport $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'camp' => ['id' => $r->camp->id, 'code' => $r->camp->code, 'name' => $r->camp->name],
                'reporter' => $r->reporter?->name,
                'payload' => $r->payload,
                'recorded_at' => $r->recorded_at->toIso8601String(),
                'synced_at' => $r->synced_at?->toIso8601String(),
                // Selisih antara dicatat di lapangan dan diterima server: tanda laporan yang tertahan offline.
                'delay_seconds' => $r->synced_at ? max(0, $r->synced_at->diffInSeconds($r->recorded_at, true)) : null,
                'location' => $r->lat === null ? null : ['lat' => round((float) $r->lat, 5), 'lng' => round((float) $r->lng, 5), 'accuracy_m' => $r->geo_accuracy_m],
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }
}
