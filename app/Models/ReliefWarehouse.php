<?php

namespace App\Models;

use App\Models\Concerns\HasPoint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReliefWarehouse extends Model
{
    use HasPoint, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['capacity_m3' => 'float'];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(WarehouseInventory::class, 'warehouse_id');
    }
}
