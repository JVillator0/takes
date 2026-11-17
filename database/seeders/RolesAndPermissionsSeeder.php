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
 * Customer-guard roles and permissions (spec 3-01). Idempotent: the deploy
 * script runs it on every deploy (D-068), so it only creates what is missing
 * and re-syncs each role to its enum-defined permissions. Admin roles arrive
 * with Filament and Shield in spec 7 (D-048).
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
    }
}
