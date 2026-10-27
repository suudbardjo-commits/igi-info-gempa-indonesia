<?php

namespace App\Enums;

enum DisasterSource: string
{
    case Bmkg = 'bmkg';
    case Usgs = 'usgs';
    case Manual = 'manual';
}
