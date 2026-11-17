<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;

/**
 * Before calling a provider: reserve the estimate's maximum charge as a hold
 * (spec 3-03 §6, D-024). The hold is state on the open usage record
 * (`max_charge_nanos`) plus the cached `customers.held_nanos`; no ledger row.
 */
final readonly class OpenUsageAction
{
    /**
     * @throws InsufficientCreditsException when available < the estimate's max charge
     */
    public function execute(
        Take|Suggestion $billable,
        Provider $provider,
        EstimateDTO $estimate,
        RatesSnapshotDTO $rates,
        ?string $model = null,
    ): UsageRecord {
        TransactionRequiredException::unlessInTransaction(self::class);

        // Lock order: the caller already locked the take/suggestion; the
        // customer row is the only money lock. Every check below runs under it.
        $customer = Customer::query()->lockForUpdate()->findOrFail($billable->customer_id);

        // One usage per billable (unique pair): a retried open returns the
        // existing record and places no second hold.
        $existing = UsageRecord::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // available = balance − held (D-024), read under the lock.
        if ($customer->available()->lessThan($estimate->maxCharge)) {
            throw new InsufficientCreditsException($customer->id, $customer->available(), $estimate->maxCharge);
        }

        $usage = new UsageRecord;
        $usage->forceFill([
            'customer_id' => $customer->id,
            'provider' => $provider,
            'model' => $model,
            'status' => UsageStatus::Open,
            'min_cost_nanos' => $estimate->minCost->toInt(),
            'max_cost_nanos' => $estimate->maxCost->toInt(),
            'min_charge_nanos' => $estimate->minCharge->toInt(),
            'max_charge_nanos' => $estimate->maxCharge->toInt(),
            'rates' => $rates->toArray(),
            'flags' => [],
            'markup_bps' => Nanodollars::DEFAULT_MARKUP_BPS,
        ]);
        $usage->billable()->associate($billable);
        $usage->save();

        $customer->held_nanos = $customer->held()->plus($estimate->maxCharge)->toInt();
        $customer->save();

        return $usage;
    }
}
