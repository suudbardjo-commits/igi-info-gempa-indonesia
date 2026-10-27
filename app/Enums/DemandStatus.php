<?php

namespace App\Enums;

enum DemandStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
