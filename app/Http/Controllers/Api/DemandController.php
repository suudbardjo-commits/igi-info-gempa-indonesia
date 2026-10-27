<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDemandRequest;
use App\Http\Resources\DemandResource;
use App\Models\CampDemand;
use App\Models\EvacuationCamp;
use App\Services\Logistics\AllocationEngine;
use App\Services\Logistics\DemandCreator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DemandController extends Controller
{
    /** Permintaan yang boleh dilihat: semua bagi petugas koordinasi, atau milik posko yang diampu. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $request->validate([
            'camp_id' => ['nullable', 'uuid'],
            // "open" = menunggu atau sebagian terpenuhi.
            'status' => ['nullable', 'in:open,pending,partial,fulfilled,cancelled'],
        ]);

        $query = CampDemand::query()
            ->with(['camp:id,code,name', 'item:id,sku,name,unit'])
            ->when($request->filled('camp_id'), fn ($q) => $q->where('camp_id', $request->string('camp_id')))
            ->when($request->input('status') === 'open', fn ($q) => $q->whereIn('status', ['pending', 'partial']))
            ->when($request->filled('status') && $request->input('status') !== 'open', fn ($q) => $q->where('status', $request->string('status')))
            // Paling mendesak dan paling lama menunggu lebih dulu.
            ->orderByRaw("CASE urgency_level WHEN 'critical' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC")
            ->orderBy('created_at');

        if (! $user->can('demand.view-all')) {
            $query->whereIn('camp_id', EvacuationCamp::where('coordinator_id', $user->id)->select('id'));
        }

        return DemandResource::collection($query->paginate(50));
    }

    public function store(StoreDemandRequest $request, EvacuationCamp $camp, DemandCreator $creator): JsonResponse
    {
        $this->authorize('createForCamp', [CampDemand::class, $camp]);

        $data = $request->validated();

        // Pengiriman ulang dengan ID yang sama (mode offline) tidak membuat permintaan ganda: 200 untuk yang sudah ada.
        ['demand' => $demand, 'created' => $created] = $creator->create($request->user(), $camp, $data, $data['id'] ?? null);

        return (new DemandResource($demand->load('camp', 'item')))->response()->setStatusCode($created ? 201 : 200);
    }

    /** Usulan alokasi stok yang dihitung saat ini (tidak mengubah stok). */
    public function plan(CampDemand $demand, AllocationEngine $engine): JsonResponse
    {
        $this->authorize('plan', CampDemand::class);

        return response()->json($engine->plan($demand->load('item', 'camp'))->toArray());
    }
}
