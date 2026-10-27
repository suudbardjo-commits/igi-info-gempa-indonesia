<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Peringatan untuk satu posko yang berada dalam radius dampak gempa. Hanya didengar koordinator
 * posko itu dan petugas koordinasi (channel private).
 */
class CampThreatenedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $disaster
     * @param  array<string, mixed>  $camp
     */
    public function __construct(
        public string $campId,
        public array $disaster,
        public array $camp,
        public float $distanceKm,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("camps.{$this->campId}")];
    }

    /** Siaran peringatan lewat antrean khusus yang diproses lebih dulu. */
    public function broadcastQueue(): string
    {
        return 'alerts';
    }

    public function broadcastAs(): string
    {
        return 'camp.threatened';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'disaster' => $this->disaster,
            'camp' => $this->camp,
            'distance_km' => $this->distanceKm,
        ];
    }
}
