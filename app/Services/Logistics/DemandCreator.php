<?php

namespace App\Services\Logistics;

use App\Enums\DemandStatus;
use App\Events\CampDemandCreatedEvent;
use App\Exceptions\IdConflictException;
use App\Models\CampDemand;
use App\Models\EvacuationCamp;
use App\Models\User;

/**
 * Membuat permintaan kebutuhan posko. Dipakai bersama oleh API biasa dan sinkronisasi offline,
 * jadi aturannya (termasuk idempotensi lewat ID buatan perangkat) hanya ada di satu tempat.
 */
class DemandCreator
{
    /**
     * @param  array{item_id: string, quantity_requested: float|int|string, urgency_level: string, notes?: ?string}  $data
     * @return array{demand: CampDemand, created: bool}
     *
     * @throws IdConflictException bila ID sudah dipakai permintaan untuk posko lain
     */
    public function create(User $user, EvacuationCamp $camp, array $data, ?string $id = null): array
    {
        if ($id !== null && ($existing = CampDemand::find($id))) {
            if ($existing->camp_id !== $camp->id) {
                throw new IdConflictException('ID permintaan sudah dipakai untuk posko lain.');
            }

            return ['demand' => $existing, 'created' => false];
        }

        $demand = CampDemand::create([
            'id' => $id,
            'camp_id' => $camp->id,
            'item_id' => $data['item_id'],
            'quantity_requested' => $data['quantity_requested'],
            'urgency_level' => $data['urgency_level'],
            'status' => DemandStatus::Pending,
            'notes' => $data['notes'] ?? null,
            'requested_by' => $user->id,
        ]);

        CampDemandCreatedEvent::dispatch($demand);

        return ['demand' => $demand, 'created' => true];
    }
}
