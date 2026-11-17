<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Auth\AdminRole;
use App\Providers\Filament\AdminPanelProvider;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The console's roles and permissions on the `web` guard (spec 7 issue 2, A08). Idempotent and safe on every
 * deploy (D-068): it creates the permission of every resource, page and widget Shield finds in the admin panel,
 * plus the custom ones (AdminPermission), and re-syncs each role to AdminRole::permissions(). A resource or page
 * added later gets its permissions on the next deploy, readable by the operator and the auditor.
 */
final class AdminRolesSeeder extends Seeder
{
    public function run(PermissionRegistrar $registrar): void
    {
        $registrar->forgetCachedPermissions();

        Filament::setCurrentPanel(AdminPanelProvider::ID);

        /** @var list<string> $panelPermissions */
        $panelPermissions = array_values(array_unique(FilamentShield::getEntitiesPermissions() ?? []));

        foreach ($panelPermissions as $permission) {
            Permission::findOrCreate($permission, AdminRole::GUARD);
        }

        foreach (AdminRole::cases() as $role) {
            Role::findOrCreate($role->value, AdminRole::GUARD)->syncPermissions($role->permissions($panelPermissions));
        }

        $registrar->forgetCachedPermissions();
    }
}
