<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Auth\CustomerRole;
use App\Models\Customer;

/**
 * Gives a customer exactly one customer-guard role. Used by `takes:showcase`
 * (D-072); spec 7's Restrict / Unrestrict action can reuse it.
 */
final readonly class AssignCustomerRoleAction
{
    public function execute(Customer $customer, CustomerRole $role): Customer
    {
        return $customer->syncRoles([$role->value]);
    }
}
