<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Auth\AdminRole;
use App\Models\User;

/**
 * System › Admins (D-086). Reading needs the Shield view keys (`ViewAny:User`, `View:User`), which the operator
 * and the auditor hold; writing needs `Create:User` / `Update:User` / `Delete:User`, which no role holds, so only
 * the super admin passes (Gate::before). Delete is decided here even for the super admin
 * (AdminPanelProvider::superAdminGate defers it): never your own account, never the last super admin.
 * DeleteAdminUserAction re-checks both under lock.
 */
final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:User');
    }

    public function view(User $user, User $admin): bool
    {
        return $user->can('View:User');
    }

    public function create(User $user): bool
    {
        return $user->can('Create:User');
    }

    public function update(User $user, User $admin): bool
    {
        return $user->can('Update:User');
    }

    public function delete(User $user, User $admin): bool
    {
        return $user->can('Delete:User')
            && ! $user->is($admin)
            && ! self::isLastSuperAdmin($admin);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    private static function isLastSuperAdmin(User $admin): bool
    {
        return $admin->hasRole(AdminRole::SuperAdmin->value)
            && User::query()->role(AdminRole::SuperAdmin->value, AdminRole::GUARD)->count() <= 1;
    }
}
