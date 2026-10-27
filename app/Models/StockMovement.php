<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger stok: hanya ditambah. Saldo di warehouse_inventories dan camp_inventories
 * adalah turunan dari baris-baris ini.
 */
class StockMovement extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'quantity' => 'string',
            'created_at' => 'datetime',
        ];
    }
}
