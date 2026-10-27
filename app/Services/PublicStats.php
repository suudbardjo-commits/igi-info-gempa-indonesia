<?php

namespace App\Services;

use App\Enums\CampStatus;
use App\Models\DisasterEvent;
use App\Models\EvacuationCamp;

/** Angka ringkas untuk beranda publik. Hanya agregat; tidak ada data pribadi. */
class PublicStats
{
    public function summary(): array
    {
        $main = fn () => DisasterEvent::query()->whereNull('canonical_event_id');

        $strongest = $main()->where('occurred_at', '>=', now()->subDays(7))
            ->whereNotNull('magnitude')->orderByDesc('magnitude')->orderByDesc('occurred_at')->first();

        $camps = fn () => EvacuationCamp::query()->where('status', '!=', CampStatus::Closed->value);

        return [
            'quakes_24h' => $main()->where('occurred_at', '>=', now()->subDay())->count(),
            'quakes_7d' => $main()->where('occurred_at', '>=', now()->subDays(7))->count(),
            'strong_7d' => $main()->where('occurred_at', '>=', now()->subDays(7))->where('magnitude', '>=', 5)->count(),
            'strongest_7d' => $strongest ? [
                'id' => $strongest->id,
                'magnitude' => $strongest->magnitude,
                'location' => $strongest->location_name,
                'occurred_at' => $strongest->occurred_at->toIso8601String(),
            ] : null,
            'camps_open' => $camps()->count(),
            'camps_full' => $camps()->where('status', CampStatus::Full->value)->count(),
            'occupants' => (int) $camps()->sum('current_occupants'),
            'capacity' => (int) $camps()->sum('capacity_people'),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
