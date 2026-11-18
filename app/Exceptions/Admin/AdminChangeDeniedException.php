<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/**
 * A refused change to System › Admins: deleting your own account, deleting (or taking the super admin role from)
 * the last super admin, or deleting an admin with ledger or audit history. The message is the console's lang
 * string.
 */
final class AdminChangeDeniedException extends RuntimeException
{
    public static function ownAccount(): self
    {
        return new self((string) __('console.admins.denied.own_account'));
    }

    public static function hasHistory(): self
    {
        return new self((string) __('console.admins.denied.has_history'));
    }

    public static function lastSuperAdmin(): self
    {
        return new self((string) __('console.admins.denied.last_super_admin'));
    }
}
