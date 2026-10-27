<?php

namespace App\Enums;

enum DisasterType: string
{
    case Earthquake = 'earthquake';
    case Tsunami = 'tsunami';
    case Flood = 'flood';
    case Volcano = 'volcano';
    case Landslide = 'landslide';
}
