<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliefDispatchItem extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_dispatched' => 'float',
            'quantity_received' => 'float',
        ];
    }

    public function dispatch(): BelongsTo
    {
        return $this->belongsTo(ReliefDispatch::class, 'dispatch_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(WarehouseInventory::class, 'inventory_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function demand(): BelongsTo
    {
        return $this->belongsTo(CampDemand::class, 'demand_id');
    }
}
