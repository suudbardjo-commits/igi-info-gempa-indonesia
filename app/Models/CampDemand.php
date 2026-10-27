<?php

namespace App\Models;

use App\Enums\DemandStatus;
use App\Enums\DemandUrgency;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampDemand extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'float',
            'quantity_fulfilled' => 'float',
            'urgency_level' => DemandUrgency::class,
            'status' => DemandStatus::class,
            'suggested_plan' => 'array',
            'suggested_at' => 'datetime',
        ];
    }

    public function camp(): BelongsTo
    {
        return $this->belongsTo(EvacuationCamp::class, 'camp_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getQuantityOutstandingAttribute(): float
    {
        return max(0, $this->quantity_requested - $this->quantity_fulfilled);
    }
}
