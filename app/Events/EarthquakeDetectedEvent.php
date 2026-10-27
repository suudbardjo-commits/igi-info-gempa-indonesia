<?php

namespace App\Events;

use App\Models\DisasterEvent;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dikirim lewat antrean (bukan ShouldBroadcastNow) supaya ingesti tidak
 * ikut macet kalau server WebSocket sedang mati.
 */
class EarthquakeDetectedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DisasterEvent $disaster,
        public float $lng,
        public float $lat,
    ) {}

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('public-disasters')];
    }

    /** Siaran peringatan lewat antrean khusus yang diproses lebih dulu. */
    public function broadcastQueue(): string
    {
        return 'alerts';
    }

    public function broadcastAs(): string
    {
        return 'earthquake.detected';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->disaster->id,
            'source' => $this->disaster->source->value,
            'magnitude' => $this->disaster->magnitude,
            'depth_km' => $this->disaster->depth_km,
            'location' => $this->disaster->location_name,
            'tsunami_potential' => $this->disaster->tsunami_potential,
            'latitude' => $this->lat,
            'longitude' => $this->lng,
            'impact_radius_km' => $this->disaster->impact_radius_km,
            'occurred_at' => $this->disaster->occurred_at->toIso8601String(),
        ];
    }
}
