<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Customer;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Customer-area policies never authorize an admin `User` (D-012): the Gate
 * runs `before()` first, so a User never reaches a method typed for
 * Customer. Admin abilities arrive with Filament and Shield (spec 7, D-048).
 */
trait AuthorizesCustomersOnly
{
    public function before(Authenticatable $user): ?Response
    {
        return $user instanceof Customer ? null : Response::deny();
    }
}
