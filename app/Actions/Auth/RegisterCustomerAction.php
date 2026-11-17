<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\RegisterCustomerDTO;
use App\Enums\Auth\CustomerRole;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Creates a self-registered customer with the default `customer` role
 * (spec 3-01). No email verification (D-030): Fortify logs the customer in
 * right after this returns.
 */
final readonly class RegisterCustomerAction
{
    public function execute(RegisterCustomerDTO $data): Customer
    {
        return DB::transaction(function () use ($data): Customer {
            $customer = new Customer;
            $customer->forceFill([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
            ])->save();

            $customer->assignRole(CustomerRole::Customer->value);

            // Welcome credit hook (D-019, D-026): spec 4 calls
            // GrantWelcomeCreditAction here, in this same transaction, so a
            // customer never exists without the grant. Not implemented in M3.

            return $customer;
        });
    }
}
