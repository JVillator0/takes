<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Auth\AdminRole;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\User;

/**
 * Locks the super admins' `users` rows (`FOR UPDATE`, id order) inside the caller's transaction and returns
 * their ids, so a check like "is this the last super admin?" holds until the caller commits. An Action, not a
 * Query: it takes row locks.
 */
final readonly class LockSuperAdminsAction
{
    /**
     * @return list<int>
     */
    public function execute(): array
    {
        TransactionRequiredException::unlessInTransaction(self::class);

        /** @var list<int> $ids */
        $ids = User::query()
            ->role(AdminRole::SuperAdmin->value, AdminRole::GUARD)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
