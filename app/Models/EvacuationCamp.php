<?php

namespace App\Models;

use App\Enums\CampStatus;
use App\Models\Concerns\HasPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class EvacuationCamp extends Model
{
    use HasPoint, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CampStatus::class,
            'capacity_people' => 'integer',
            'current_occupants' => 'integer',
            'occupants_updated_at' => 'datetime',
        ];
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function volunteers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'camp_volunteers', 'camp_id', 'user_id')->withTimestamps();
    }

    /** Koordinator posko ini, atau relawan yang ditugaskan ke posko ini. */
    public function canBeReportedBy(User $user): bool
    {
        return (int) $this->coordinator_id === $user->id
            || $this->volunteers()->where('users.id', $user->id)->exists();
    }

    /** Posko yang boleh dilaporkan pengguna (sebagai koordinator atau relawan). */
    public function scopeReportableBy(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q
            ->where('coordinator_id', $user->id)
            ->orWhereIn('id', DB::table('camp_volunteers')->where('user_id', $user->id)->select('camp_id')));
    }

    public function demands(): HasMany
    {
        return $this->hasMany(CampDemand::class, 'camp_id');
    }
}
