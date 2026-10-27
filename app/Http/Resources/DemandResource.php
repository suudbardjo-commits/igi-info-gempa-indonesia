<?php

namespace App\Http\Resources;

use App\Models\CampDemand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CampDemand */
class DemandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plan = $this->suggested_plan;

        return [
            'id' => $this->id,
            'camp' => $this->whenLoaded('camp', fn () => ['id' => $this->camp->id, 'code' => $this->camp->code, 'name' => $this->camp->name]),
            'item' => $this->whenLoaded('item', fn () => ['id' => $this->item->id, 'sku' => $this->item->sku, 'name' => $this->item->name, 'unit' => $this->item->unit]),
            'quantity_requested' => $this->quantity_requested,
            'quantity_fulfilled' => $this->quantity_fulfilled,
            'quantity_remaining' => max(0, $this->quantity_requested - $this->quantity_fulfilled),
            'urgency_level' => $this->urgency_level->value,
            'status' => $this->status->value,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'suggestion' => $plan ? [
                'allocated' => $plan['allocated'],
                'shortfall' => $plan['shortfall'],
                'complete' => $plan['complete'],
                'warehouses' => count($plan['sources']),
                'computed_at' => $this->suggested_at?->toIso8601String(),
            ] : null,
        ];
    }
}
