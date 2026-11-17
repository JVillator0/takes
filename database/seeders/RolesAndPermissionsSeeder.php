<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\CustomerRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Every role and permission, on both guards. Idempotent: the deploy script
 * runs it on every deploy (D-068, spec 9), so it only creates what is
 * missing and re-syncs each role to its enum-defined permissions. Customer
 * roles are here (spec 3-01); the console's admin roles come from
 * AdminRolesSeeder (spec 7, D-048), called last.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        foreach (CustomerPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, CustomerRole::GUARD);
        }

        foreach (CustomerRole::cases() as $role) {
            Role::findOrCreate($role->value, CustomerRole::GUARD)->syncPermissions(
                array_map(fn (CustomerPermission $permission): string => $permission->value, $role->permissions()),
            );
        }

        $registrar->forgetCachedPermissions();

        $this->call(AdminRolesSeeder::class);
    }
}
