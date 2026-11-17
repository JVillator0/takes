<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Shield's Roles resource (System › Roles), read-only: roles are defined in code (AdminRole::permissions()) and
 * re-synced by AdminRolesSeeder on every deploy, so an edit here would be silently reverted. The super admin's
 * Gate::before also refuses role writes (AdminPanelProvider::superAdminGate). Shield registers this policy.
 */
final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('ViewAny:Role');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->can('View:Role');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Role $role): bool
    {
        return false;
    }

    public function delete(User $user, Role $role): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
