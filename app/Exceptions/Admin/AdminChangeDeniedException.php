<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/**
 * A change to System › Admins that would lock the console: deleting your own account, or deleting (or taking the
 * super admin role from) the last super admin. The message is the console's lang string.
 */
final class AdminChangeDeniedException extends RuntimeException
{
    public static function ownAccount(): self
    {
        return new self((string) __('console.admins.denied.own_account'));
    }

    public static function lastSuperAdmin(): self
    {
        return new self((string) __('console.admins.denied.last_super_admin'));
    }
}
