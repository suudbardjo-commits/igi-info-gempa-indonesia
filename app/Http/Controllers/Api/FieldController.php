<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DispatchResource;
use App\Models\EvacuationCamp;
use App\Models\Item;
use App\Models\ReliefDispatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FieldController extends Controller
{
    /**
     * Semua yang dibutuhkan aplikasi lapangan untuk bekerja offline dalam satu permintaan:
     * posko yang boleh dilaporkan, daftar barang (untuk mengajukan kebutuhan), dan kiriman yang sedang menuju
     * posko yang diampu. Perangkat menyimpannya di IndexedDB.
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        $camps = EvacuationCamp::query()->reportableBy($user)->orderBy('name')
            ->get(['id', 'name', 'code', 'status', 'capacity_people', 'current_occupants', 'coordinator_id', 'occupants_updated_at']);

        // Hanya koordinator yang boleh menerima kiriman, jadi hanya posko yang diampunya.
        $incoming = ReliefDispatch::query()
            ->with(['warehouse:id,code,name,manager_id', 'camp:id,code,name,coordinator_id', 'items.item:id,sku,name,unit', 'items.inventory:id,batch_number,expiry_date'])
            ->whereIn('destination_camp_id', $camps->where('coordinator_id', $user->id)->pluck('id'))
            ->whereIn('status', ['dispatched', 'in_transit'])
            ->latest()->limit(50)->get();

        return response()->json([
            'camps' => $camps->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'status' => $c->status->value,
                'capacity_people' => $c->capacity_people, 'current_occupants' => $c->current_occupants,
                'occupants_updated_at' => $c->occupants_updated_at?->toIso8601String(),
                'role' => (int) $c->coordinator_id === $user->id ? 'koordinator' : 'relawan',
            ])->values(),
            'items' => Item::query()->orderBy('name')->get(['id', 'sku', 'name', 'unit'])
                ->map(fn ($i) => ['id' => $i->id, 'sku' => $i->sku, 'name' => $i->name, 'unit' => $i->unit])->values(),
            'incoming' => DispatchResource::collection($incoming)->resolve($request),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
