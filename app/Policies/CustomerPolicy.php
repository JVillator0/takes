<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Policies\Concerns\AuthorizesCustomersOnly;
use Illuminate\Auth\Access\Response;

/**
 * A customer's own account settings (spec 3-01).
 */
final readonly class CustomerPolicy
{
    use AuthorizesCustomersOnly;

    /**
     * Name, email and password. A shared demo login is public (P02, README),
     * so changing its credentials would lock every other evaluator out (D-072).
     */
    public function updateAccount(Customer $customer, Customer $account): Response
    {
        if ($account->id !== $customer->id) {
            return Response::denyAsNotFound();
        }

        return $account->isSharedDemoAccount()
            ? DenialReason::DemoAccountLocked->deny()
            : Response::allow();
    }
}
