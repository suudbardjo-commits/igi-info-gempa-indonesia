<?php

namespace App\Http\Resources;

use App\Models\ReliefDispatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReliefDispatch */
class DispatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->dispatch_code,
            'status' => $this->status->value,
            'warehouse' => $this->whenLoaded('warehouse', fn () => ['id' => $this->warehouse->id, 'code' => $this->warehouse->code, 'name' => $this->warehouse->name]),
            'camp' => $this->whenLoaded('camp', fn () => ['id' => $this->camp->id, 'code' => $this->camp->code, 'name' => $this->camp->name]),
            'driver_name' => $this->driver_name,
            'vehicle_plate_number' => $this->vehicle_plate_number,
            'departure_time' => $this->departure_time?->toIso8601String(),
            'arrival_time' => $this->arrival_time?->toIso8601String(),
            'receiver_notes' => $this->receiver_notes,
            // Aksi yang boleh dilakukan pengguna ini; antarmuka memakainya untuk menampilkan tombol.
            'can' => $request->user() ? [
                'send' => $request->user()->can('send', $this->resource),
                'cancel' => $request->user()->can('cancel', $this->resource),
                'receive' => $request->user()->can('receive', $this->resource),
            ] : null,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'item' => ['sku' => $i->item->sku, 'name' => $i->item->name, 'unit' => $i->item->unit],
                'batch_number' => $i->inventory?->batch_number,
                'expiry_date' => $i->inventory?->expiry_date?->toDateString(),
                'quantity_dispatched' => $i->quantity_dispatched,
                'quantity_received' => $i->quantity_received,
            ])),
        ];
    }
}
