<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Posko melaporkan kondisi darurat. Sampai ke konsol logistik supaya petugas bisa bertindak. */
class CampConditionReportedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /** @param array{name: string, code: string} $camp */
    public function __construct(
        public array $camp,
        public string $severity,
        public ?string $note,
        public string $recordedAt,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('logistics')];
    }

    public function broadcastQueue(): string
    {
        return 'alerts';
    }

    public function broadcastAs(): string
    {
        return 'camp.condition';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['camp' => $this->camp, 'severity' => $this->severity, 'note' => $this->note, 'recorded_at' => $this->recordedAt];
    }
}
