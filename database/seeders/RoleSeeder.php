<?php

namespace Database\Seeders;

use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        'disaster.verify' => [Role::BpbdOperator],
        'disaster.create-manual' => [Role::BpbdOperator],
        'evacuation.instruct' => [Role::BpbdOperator],
        'warehouse.manage-stock' => [Role::WarehouseManager],
        'dispatch.create' => [Role::WarehouseManager],
        'dispatch.receive' => [Role::CampCoordinator],
        // Melewati geofence 500 m dengan alasan tertulis; tercatat di jejak audit.
        'dispatch.override-geofence' => [Role::BpbdOperator],
        'demand.view-all' => [Role::BpbdOperator, Role::WarehouseManager],
        'demand.create' => [Role::CampCoordinator],
        'camp.update-occupants' => [Role::CampCoordinator, Role::FieldVolunteer],
        'field-report.create' => [Role::CampCoordinator, Role::FieldVolunteer],
        'audit.view' => [Role::BpbdOperator],
        'announcement.manage' => [Role::BpbdOperator],
        // Meninjau pendaftaran akun petugas; operator hanya boleh memberi peran lapangan dan gudang (lihat RegistrationController).
        'user.review' => [Role::BpbdOperator],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web');
        }

        foreach (self::PERMISSIONS as $name => $roles) {
            $permission = Permission::findOrCreate($name, 'web');

            foreach ($roles as $role) {
                RoleModel::findByName($role->value, 'web')->givePermissionTo($permission);
            }
        }

        // super-admin lolos semua pengecekan lewat Gate::before di AppServiceProvider.
    }
}
