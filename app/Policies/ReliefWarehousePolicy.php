<?php

namespace App\Policies;

use App\Models\ReliefWarehouse;
use App\Models\User;

class ReliefWarehousePolicy
{
    /** Mengelola stok: hanya pengelola gudang itu sendiri. */
    public function manageStock(User $user, ReliefWarehouse $warehouse): bool
    {
        return $user->can('warehouse.manage-stock') && (int) $warehouse->manager_id === $user->id;
    }

    /** Melihat stok: pengelola gudang itu, atau petugas koordinasi yang memantau semua gudang. */
    public function viewStock(User $user, ReliefWarehouse $warehouse): bool
    {
        return $this->manageStock($user, $warehouse) || $user->can('demand.view-all');
    }
}
