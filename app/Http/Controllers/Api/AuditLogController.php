<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Logistics\AuditLogger;
use App\Services\Logistics\AuditPresenter;
use App\Services\Logistics\LedgerReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403);

        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = DB::table('logistics_audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->selectRaw('a.id, a.action, a.entity_type, a.entity_id, a.new_values, a.ip_address, a.geo_accuracy_m, a.created_at,
                         u.name AS user_name, u.email AS user_email,
                         ST_X(a.geo_point) AS lng, ST_Y(a.geo_point) AS lat')
            ->when(isset($data['action']), fn ($q) => $q->where('a.action', $data['action']))
            ->orderByDesc('a.id')
            ->paginate(40);

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => AuditPresenter::row($r))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'actions' => AuditPresenter::LABELS,
        ]);
    }

    /** Memeriksa seluruh rantai audit dan kecocokan stok dengan ledger. Bisa lama kalau datanya besar. */
    public function integrity(Request $request, AuditLogger $audit, LedgerReconciler $reconciler): JsonResponse
    {
        abort_unless($request->user()->can('audit.view'), 403);

        $brokenId = $audit->verify();
        $mismatches = $reconciler->mismatches();

        return response()->json([
            'audit' => [
                'ok' => $brokenId === null,
                'total' => DB::table('logistics_audit_logs')->count(),
                'broken_id' => $brokenId,
            ],
            'ledger' => [
                'ok' => $mismatches === [],
                'mismatches' => $mismatches,
            ],
            'checked_at' => now()->toIso8601String(),
        ]);
    }
}
