<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Policies\Concerns\AuthorizesCustomersOnly;
use Illuminate\Auth\Access\Response;

/**
 * Buying credits (spec 3-01): `credits.purchase`. The shared `auth.can`
 * prop and spec 3-03's purchase request ask the same question here. The
 * read-only showcase (D-072) has no such permission.
 */
final readonly class PurchasePolicy
{
    use AuthorizesCustomersOnly;

    public function create(Customer $customer): Response
    {
        return $customer->checkPermissionTo(CustomerPermission::CreditsPurchase->value)
            ? Response::allow()
            : DenialReason::AccountRestricted->deny();
    }
}
