<?php

namespace App\Models;

use App\Models\Concerns\HasPoint;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laporan relawan/koordinator. ID dibuat di perangkat (UUID) sehingga pengiriman ulang tidak menggandakan.
 * `recorded_at` adalah waktu menurut perangkat saat laporan dibuat; `synced_at` waktu server menerimanya.
 */
class FieldReport extends Model
{
    use HasPoint, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'recorded_at' => 'datetime',
            'synced_at' => 'datetime',
            'geo_accuracy_m' => 'float',
        ];
    }

    public function camp(): BelongsTo
    {
        return $this->belongsTo(EvacuationCamp::class, 'camp_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
