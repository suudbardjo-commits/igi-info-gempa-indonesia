<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseInventory extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity_available' => 'float',
            'quantity_reserved' => 'float',
            'expiry_date' => 'date',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(ReliefWarehouse::class, 'warehouse_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Stok yang masih bebas dipakai: tersedia dikurangi yang sudah direservasi. */
    public function getQuantityFreeAttribute(): float
    {
        return $this->quantity_available - $this->quantity_reserved;
    }
}
