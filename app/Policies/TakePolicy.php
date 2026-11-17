<?php

declare(strict_types=1);

namespace App\Policies;

use App\DTOs\Billing\EstimateDTO;
use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Models\Take;
use App\Policies\Concerns\AuthorizesCustomersOnly;
use App\Queries\Takes\GetDailyTakeCountQuery;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Config;

/**
 * Permission + state with reason codes (spec 3-01, D-015, D-050). This is UX:
 * the submit action re-checks credits under the customer lock (spec 3-03 §6).
 */
final readonly class TakePolicy
{
    use AuthorizesCustomersOnly;

    public function __construct(private GetDailyTakeCountQuery $dailyTakeCount) {}

    /**
     * Checked in this order: permission, per-customer cap, global cap, credits.
     */
    public function create(Customer $customer, EstimateDTO $estimate): Response
    {
        if (! $customer->checkPermissionTo(CustomerPermission::TakesCreate->value)) {
            return DenialReason::AccountRestricted->deny();
        }

        if ($this->dailyTakeCount->execute($customer) >= Config::integer('takes.daily_cap_per_customer')) {
            return DenialReason::DailyTakeCap->deny();
        }

        // Today's shared capacity, any customer (registered or demo), D-065.
        if ($this->dailyTakeCount->execute() >= Config::integer('takes.daily_cap_global')) {
            return DenialReason::DemoQuotaReached->deny();
        }

        // Also covers a negative available after an over-estimate (spec 3-03 §6).
        if ($customer->available()->lessThan($estimate->maxCharge)) {
            return DenialReason::InsufficientCredits->deny();
        }

        return Response::allow();
    }

    /**
     * Ownership only, so the read-only showcase (D-072) still sees its
     * history. Another customer's take is a 404, never a 403 (spec 2-02).
     */
    public function view(Customer $customer, Take $take): Response
    {
        return $take->customer_id === $customer->id ? Response::allow() : Response::denyAsNotFound();
    }
}
