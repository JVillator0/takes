<?php

declare(strict_types=1);

namespace App\Policies;

use App\DTOs\Billing\EstimateDTO;
use App\Enums\Auth\CustomerPermission;
use App\Enums\Auth\DenialReason;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Policies\Concerns\AuthorizesCustomersOnly;
use App\Queries\Billing\GetDailyAiSpendQuery;
use App\Queries\Suggestions\GetDailySuggestionCountQuery;
use App\Support\Money\Nanodollars;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Config;

/**
 * "Describe a change" (spec 3-01, D-050). The 6/min/customer throttle is the
 * route's (`rate_limited`), not this policy's. Spec 8's action re-checks
 * credits under the customer lock.
 */
final readonly class SuggestionPolicy
{
    use AuthorizesCustomersOnly;

    public function __construct(
        private GetDailyAiSpendQuery $dailyAiSpend,
        private GetDailySuggestionCountQuery $dailySuggestionCount,
    ) {}

    /**
     * Checked in this order: permission, AI paused (switch or global budget),
     * per-customer daily limit, credits. Spec 8 passes its per-request
     * estimate (D-055); without one, any positive available credit passes.
     */
    public function create(Customer $customer, ?EstimateDTO $estimate = null): Response
    {
        if (! $customer->checkPermissionTo(CustomerPermission::AiSuggest->value)) {
            return DenialReason::AccountRestricted->deny();
        }

        if (! Config::boolean('takes.ai.enabled') || $this->budgetReached()) {
            return DenialReason::AiPaused->deny();
        }

        if ($this->dailySuggestionCount->execute($customer) >= Config::integer('takes.ai.daily_limit_per_customer')) {
            return DenialReason::AiDailyLimit->deny();
        }

        $available = $customer->available();
        $cannotAfford = $estimate === null
            ? $available->isNegative() || $available->isZero()
            : $available->lessThan($estimate->maxCharge);

        return $cannotAfford ? DenialReason::InsufficientCredits->deny() : Response::allow();
    }

    /** Another customer's suggestion is a 404, never a 403. */
    public function view(Customer $customer, Suggestion $suggestion): Response
    {
        return $suggestion->customer_id === $customer->id ? Response::allow() : Response::denyAsNotFound();
    }

    private function budgetReached(): bool
    {
        return $this->dailyAiSpend->execute()->greaterThanOrEqual(
            Nanodollars::fromInt(Config::integer('takes.ai.daily_budget_nanos')),
        );
    }
}
