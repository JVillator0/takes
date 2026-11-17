<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\UpdateCustomerProfileDTO;
use App\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Changes a customer's name and email. Shared demo logins are locked
 * (`demo_account_locked`, D-072); the check lives here so every caller,
 * not only the settings page, goes through it.
 */
final readonly class UpdateCustomerProfileAction
{
    /** @throws AuthorizationException */
    public function execute(Customer $customer, UpdateCustomerProfileDTO $data): Customer
    {
        Gate::forUser($customer)->authorize('updateAccount', $customer);

        $customer->forceFill([
            'name' => $data->name,
            'email' => $data->email,
        ])->save();

        return $customer;
    }
}
