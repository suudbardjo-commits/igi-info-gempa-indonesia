<?php

namespace App\Policies;

use App\Models\EvacuationCamp;
use App\Models\User;

class CampDemandPolicy
{
    /** Hanya koordinator posko itu sendiri yang boleh mengajukan kebutuhan atas nama posko. */
    public function createForCamp(User $user, EvacuationCamp $camp): bool
    {
        return $user->can('demand.create') && (int) $camp->coordinator_id === $user->id;
    }

    /** Melihat usulan alokasi stok gudang: tugas pengelola gudang. */
    public function plan(User $user): bool
    {
        return $user->can('dispatch.create');
    }
}
