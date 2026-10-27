<?php

namespace App\Enums;

enum DispatchStatus: string
{
    case Draft = 'draft';
    case Dispatched = 'dispatched';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
