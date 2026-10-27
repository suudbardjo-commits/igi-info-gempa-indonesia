<?php

namespace App\Models;

use App\Enums\DisasterSource;
use App\Enums\DisasterType;
use App\Models\Concerns\HasPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisasterEvent extends Model
{
    use HasPoint, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => DisasterSource::class,
            'type' => DisasterType::class,
            'magnitude' => 'float',
            'depth_km' => 'float',
            'is_verified' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Daftar untuk tampilan publik: kejadian utama saja (tanpa laporan duplikat
     * dari sumber lain), terbaru lebih dulu, sudah membawa longitude/latitude.
     */
    public function scopePublicFeed(
        Builder $query,
        int $hours = 168,
        ?float $minMagnitude = null,
        ?string $source = null,
        int $limit = 200,
    ): Builder {
        return $query->withCoordinates()
            ->whereNull('canonical_event_id')
            ->where('occurred_at', '>=', now()->subHours($hours))
            ->when($minMagnitude !== null, fn ($q) => $q->where('magnitude', '>=', $minMagnitude))
            ->when($source !== null, fn ($q) => $q->where('source', $source))
            ->orderByDesc('occurred_at')
            ->limit($limit);
    }

    /** Masih cukup baru untuk dianggap peringatan, bukan data riwayat. */
    public function isFresh(): bool
    {
        return $this->occurred_at->gte(now()->subMinutes((int) config('disaster.alerts.max_age_minutes')));
    }

    public function canonicalEvent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_event_id');
    }
}
