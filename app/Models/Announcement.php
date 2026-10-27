<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    use HasUuids;

    public const LEVELS = ['info', 'penting', 'darurat'];

    protected $guarded = [];

    protected static function booted(): void
    {
        // Jam PHP, bukan default database: jam database bisa beberapa milidetik mendahului jam PHP,
        // sehingga pengumuman baru sempat dianggap "belum terbit".
        static::creating(fn (self $a) => $a->published_at ??= now());
    }

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** Yang sedang berlaku: sudah terbit dan belum kedaluwarsa. Yang paling mendesak, lalu terbaru, di atas. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw("case level when 'darurat' then 0 when 'penting' then 1 else 2 end")
            ->orderByDesc('published_at');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,
            'published_at' => $this->published_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
