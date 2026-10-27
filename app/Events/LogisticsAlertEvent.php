<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Ringkasan dampak satu gempa untuk konsol logistik: berapa posko terancam dan mana saja. */
class LogisticsAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $disaster
     * @param  list<array{name: string, code: string, distance_km: float}>  $camps
     */
    public function __construct(public array $disaster, public array $camps) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('logistics')];
    }

    /** Siaran peringatan lewat antrean khusus yang diproses lebih dulu. */
    public function broadcastQueue(): string
    {
        return 'alerts';
    }

    public function broadcastAs(): string
    {
        return 'logistics.alert';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['disaster' => $this->disaster, 'camps' => $this->camps];
    }
}
