<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super-admin';
    case BpbdOperator = 'bpbd-operator';
    case WarehouseManager = 'warehouse-manager';
    case CampCoordinator = 'camp-coordinator';
    case FieldVolunteer = 'field-volunteer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::BpbdOperator => 'Operator BPBD',
            self::WarehouseManager => 'Manajer Gudang',
            self::CampCoordinator => 'Koordinator Posko',
            self::FieldVolunteer => 'Relawan Lapangan',
        };
    }
}
