<?php

namespace App\Enums;

enum CampStatus: string
{
    case Active = 'active';
    case Full = 'full';
    case Evacuated = 'evacuated';
    case Closed = 'closed';
}
