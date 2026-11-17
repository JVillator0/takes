<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Sets a new password (hashed by the model cast). Shared demo logins are
 * locked (`demo_account_locked`, D-072).
 */
final readonly class UpdateCustomerPasswordAction
{
    /** @throws AuthorizationException */
    public function execute(Customer $customer, string $password): void
    {
        Gate::forUser($customer)->authorize('updateAccount', $customer);

        $customer->forceFill(['password' => $password])->save();
    }
}
