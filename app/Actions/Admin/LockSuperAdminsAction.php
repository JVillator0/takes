<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Auth\AdminRole;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Serializes every change that could remove a super admin, inside the caller's transaction: it locks the
 * `super_admin` row of `roles` (`FOR UPDATE`) and only then reads the role's members in a new statement. Under
 * READ COMMITTED each statement takes a fresh snapshot, so a caller that waited for the lock sees the members as
 * the previous holder committed them; "is this the last super admin?" then holds until the caller commits. Locking
 * the members' `users` rows instead would not do: a `FOR UPDATE` re-checks only those rows, never the
 * `model_has_roles` membership a concurrent demote changed. An Action, not a Query: it takes a row lock.
 */
final readonly class LockSuperAdminsAction
{
    /**
     * @return list<int> the super admins' ids, read after the lock
     */
    public function execute(): array
    {
        TransactionRequiredException::unlessInTransaction(self::class);

        Role::query()
            ->where('name', AdminRole::SuperAdmin->value)
            ->where('guard_name', AdminRole::GUARD)
            ->lockForUpdate()
            ->first();

        $ids = User::query()
            ->role(AdminRole::SuperAdmin->value, AdminRole::GUARD)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_map(fn (mixed $id): int => (int) $id, $ids));
    }
}
