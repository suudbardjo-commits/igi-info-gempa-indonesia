<?php

namespace App\Policies;

use App\Models\ReliefDispatch;
use App\Models\User;

class ReliefDispatchPolicy
{
    /** Membuat pengiriman dari usulan alokasi. */
    public function create(User $user): bool
    {
        return $user->can('dispatch.create');
    }

    /** Memberangkatkan atau membatalkan: hanya pengelola gudang asal. */
    public function send(User $user, ReliefDispatch $dispatch): bool
    {
        return $user->can('dispatch.create') && (int) $dispatch->warehouse->manager_id === $user->id;
    }

    public function cancel(User $user, ReliefDispatch $dispatch): bool
    {
        return $this->send($user, $dispatch);
    }

    /**
     * Menerima di posko: koordinator posko tujuan. Operator BPBD (yang berhak melewati geofence)
     * boleh menerima atas nama posko, misalnya kalau koordinator tidak bisa dihubungi.
     */
    public function receive(User $user, ReliefDispatch $dispatch): bool
    {
        if ($user->can('dispatch.receive') && (int) $dispatch->camp->coordinator_id === $user->id) {
            return true;
        }

        return $user->can('dispatch.override-geofence');
    }

    public function view(User $user, ReliefDispatch $dispatch): bool
    {
        return $user->can('demand.view-all')
            || (int) $dispatch->warehouse->manager_id === $user->id
            || (int) $dispatch->camp->coordinator_id === $user->id;
    }
}
