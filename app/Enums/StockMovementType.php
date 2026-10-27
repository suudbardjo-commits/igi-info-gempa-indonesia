<?php

namespace App\Enums;

enum StockMovementType: string
{
    case WarehouseIn = 'warehouse_in';
    case Reserve = 'reserve';
    case ReleaseReservation = 'release_reservation';
    case DispatchOut = 'dispatch_out';
    case CampIn = 'camp_in';
    case Adjustment = 'adjustment';
}
