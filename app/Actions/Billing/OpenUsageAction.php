<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\DTOs\Billing\EstimateDTO;
use App\DTOs\Billing\RatesSnapshotDTO;
use App\Enums\Billing\Provider;
use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Exceptions\Billing\UsageAlreadyClosedException;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\Take;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use InvalidArgumentException;

/**
 * Before calling a provider: reserve the estimate's maximum charge as a hold
 * (spec 3-03 §6, D-024). The hold is state on the open usage record
 * (`max_charge_nanos`) plus the cached `customers.held_nanos`; no ledger row.
 */
final readonly class OpenUsageAction
{
    /**
     * @throws InsufficientCreditsException when available < the estimate's max charge
     * @throws UsageAlreadyClosedException when the billable's usage is already settled or voided
     * @throws InvalidArgumentException when the estimate's charges are not its costs with the markup snapshotted here
     */
    public function execute(
        Take|Suggestion $billable,
        Provider $provider,
        EstimateDTO $estimate,
        RatesSnapshotDTO $rates,
        ?string $model = null,
    ): UsageRecord {
        TransactionRequiredException::unlessInTransaction(self::class);
        self::assertDefaultMarkup($estimate);

        // Lock order: the caller already locked the take/suggestion; the
        // customer row is the only money lock. Every check below runs under it.
        $customer = Customer::query()->lockForUpdate()->findOrFail($billable->customer_id);

        // One usage per billable (unique pair). A retried open returns the
        // still-open record and places no second hold. A settled or voided one
        // means the billable's provider call is already finished: calling the
        // provider again would be work with no hold, so refuse.
        $existing = UsageRecord::query()
            ->where('billable_type', $billable->getMorphClass())
            ->where('billable_id', $billable->getKey())
            ->first();

        if ($existing !== null) {
            return $existing->status === UsageStatus::Open
                ? $existing
                : throw UsageAlreadyClosedException::for($existing);
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

    /**
     * The hold (maxCharge) and the later charge must use the same markup:
     * Settle charges with the `markup_bps` snapshotted below, so an estimate
     * built with any other markup is refused before anything is locked.
     */
    private static function assertDefaultMarkup(EstimateDTO $estimate): void
    {
        $markupBps = Nanodollars::DEFAULT_MARKUP_BPS;

        if (! $estimate->minCharge->equals($estimate->minCost->withMarkup($markupBps))
            || ! $estimate->maxCharge->equals($estimate->maxCost->withMarkup($markupBps))) {
            throw new InvalidArgumentException("The estimate's charges are not its costs with the {$markupBps} bps markup.");
        }
    }
}
