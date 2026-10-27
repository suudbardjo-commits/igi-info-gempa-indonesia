<?php

namespace App\Models;

use App\Enums\DispatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReliefDispatch extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DispatchStatus::class,
            'departure_time' => 'datetime',
            'arrival_time' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(ReliefWarehouse::class, 'source_warehouse_id');
    }

    public function camp(): BelongsTo
    {
        return $this->belongsTo(EvacuationCamp::class, 'destination_camp_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReliefDispatchItem::class, 'dispatch_id');
    }
}
