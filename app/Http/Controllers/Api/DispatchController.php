<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceiveDispatchRequest;
use App\Http\Requests\SendDispatchRequest;
use App\Http\Resources\DispatchResource;
use App\Models\CampDemand;
use App\Models\EvacuationCamp;
use App\Models\ReliefDispatch;
use App\Models\ReliefWarehouse;
use App\Services\Logistics\AllocationEngine;
use App\Services\Logistics\DispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DispatchController extends Controller
{
    // manager_id dan coordinator_id ikut dimuat karena policy memakainya untuk bendera hak aksi di DispatchResource.
    private const WITH = ['warehouse:id,code,name,manager_id', 'camp:id,code,name,coordinator_id', 'items.item:id,sku,name,unit', 'items.inventory:id,batch_number,expiry_date'];

    public function __construct(private DispatchService $dispatches) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $request->validate(['status' => ['nullable', 'in:draft,dispatched,in_transit,received,cancelled']]);

        $query = ReliefDispatch::query()
            ->with(self::WITH)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest();

        // Petugas koordinasi melihat semuanya; yang lain hanya pengiriman gudang atau posko yang diampunya.
        if (! $user->can('demand.view-all')) {
            $query->where(fn ($q) => $q
                ->whereIn('source_warehouse_id', ReliefWarehouse::where('manager_id', $user->id)->select('id'))
                ->orWhereIn('destination_camp_id', EvacuationCamp::where('coordinator_id', $user->id)->select('id')));
        }

        return DispatchResource::collection($query->paginate(50));
    }

    public function show(ReliefDispatch $dispatch): DispatchResource
    {
        $this->authorize('view', $dispatch);

        return new DispatchResource($dispatch->load(self::WITH));
    }

    /**
     * Membuat pengiriman draft untuk sebuah permintaan. Rencana dihitung ulang di server saat ini,
     * bukan dipercayai dari klien, lalu stoknya direservasi.
     */
    public function store(CampDemand $demand, AllocationEngine $engine): JsonResponse
    {
        $this->authorize('create', ReliefDispatch::class);

        $plan = $engine->plan($demand->load('item', 'camp'));

        if ($plan->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada stok bebas yang bisa dialokasikan untuk permintaan ini.',
                'plan' => $plan->toArray(),
            ], 422);
        }

        $created = $this->dispatches->createFromPlan($demand, $plan, request()->user());

        $loaded = ReliefDispatch::with(self::WITH)->whereIn('id', $created->pluck('id'))->get();

        return response()->json([
            'dispatches' => DispatchResource::collection($loaded),
            'complete' => $plan->isComplete(),
            'shortfall' => $plan->shortfall,
        ], 201);
    }

    public function send(SendDispatchRequest $request, ReliefDispatch $dispatch): DispatchResource
    {
        $this->authorize('send', $dispatch);

        $sent = $this->dispatches->send(
            $dispatch,
            $request->user(),
            $request->input('driver_name'),
            $request->input('vehicle_plate_number'),
            $this->geo($request),
        );

        return new DispatchResource($sent->load(self::WITH));
    }

    public function cancel(Request $request, ReliefDispatch $dispatch): DispatchResource
    {
        $this->authorize('cancel', $dispatch);

        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return new DispatchResource($this->dispatches->cancel($dispatch, $request->user(), $data['reason'])->load(self::WITH));
    }

    public function receive(ReceiveDispatchRequest $request, ReliefDispatch $dispatch): DispatchResource
    {
        $this->authorize('receive', $dispatch);

        $received = collect($request->input('received', []))
            ->mapWithKeys(fn ($row) => [$row['dispatch_item_id'] => $row['quantity']])
            ->all();

        $done = $this->dispatches->receive(
            $dispatch,
            $request->user(),
            $this->geo($request),
            $received,
            $request->input('notes'),
            $request->input('geofence_override_reason'),
        );

        return new DispatchResource($done->load(self::WITH));
    }

    /** @return array{lat: float, lng: float, accuracy: float|null}|null */
    private function geo(Request $request): ?array
    {
        if (! $request->filled('latitude')) {
            return null;
        }

        return [
            'lat' => (float) $request->input('latitude'),
            'lng' => (float) $request->input('longitude'),
            'accuracy' => $request->filled('accuracy') ? (float) $request->input('accuracy') : null,
        ];
    }
}
