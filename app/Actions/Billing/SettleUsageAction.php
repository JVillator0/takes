<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\DTOs\Billing\RatesSnapshotDTO;
use App\DTOs\Billing\UsageUnitsDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Enums\Billing\UsageFlag;
use App\Enums\Billing\UsageKind;
use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use App\Support\Money\TokenCost;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Provider work succeeded: charge cost + markup exactly once and release the
 * hold (spec 3-03 §6). Settling a usage that is not open is a no-op, so a
 * replayed webhook or a racing reconcile can never charge twice.
 */
final readonly class SettleUsageAction
{
    public function __construct(
        private RecordLedgerEntryAction $recordLedgerEntry,
        private TokenCost $tokenCost,
    ) {}

    /**
     * @param  RatesSnapshotDTO  $rates  the rates the provider actually applied (D-055)
     * @param  Nanodollars|null  $reportedCost  the provider's own cost figure, if it sent one
     * @return UsageRecord the usage as re-read under the lock
     */
    public function execute(
        UsageRecord $usage,
        UsageUnitsDTO $units,
        RatesSnapshotDTO $rates,
        CarbonImmutable $completedAt,
        ?string $externalId = null,
        ?Nanodollars $reportedCost = null,
    ): UsageRecord {
        TransactionRequiredException::unlessInTransaction(self::class);

        // The customer row is the only money lock; the usage is re-read under
        // it, never locked separately (spec 3-03 §6, lock order).
        $customer = Customer::query()->lockForUpdate()->findOrFail($usage->customer_id);
        $usage = UsageRecord::query()->findOrFail($usage->id);

        if ($usage->status !== UsageStatus::Open) {
            return $usage;
        }

        // cost = ceil(Σ units × rate / 1M); TokenCost logs when the ceil rounds.
        $cost = $this->tokenCost->compute($units->tokens, $rates->toArray());
        $charge = $cost->withMarkup($usage->markup_bps);
        $maxCharge = Nanodollars::fromInt($usage->max_charge_nanos);
        $flags = $usage->flags;
        $context = ['usage_record_id' => $usage->id, 'customer_id' => $customer->id];

        // We always settle with units × rates; a disagreeing provider figure
        // is flagged for reconciliation, never used (spec 3-03 §2).
        if ($reportedCost !== null && ! $reportedCost->equals($cost)) {
            $flags[] = UsageFlag::CostMismatch->value;
            Log::warning('billing.cost_mismatch', [...$context, 'cost_nanos' => $cost->toInt(), 'reported_cost_nanos' => $reportedCost->toInt()]);
        }

        // Actual > hold: still charge the actual amount (correct money first);
        // available may go negative by the difference (spec 3-03 §6).
        if ($maxCharge->lessThan($charge)) {
            $flags[] = UsageFlag::OverEstimate->value;
            Log::warning('billing.over_estimate', [...$context, 'charge_nanos' => $charge->toInt(), 'max_charge_nanos' => $maxCharge->toInt()]);
        }

        // Release this usage's hold (D-024).
        $customer->held_nanos = $customer->held()->minus($maxCharge)->toInt();
        $customer->save();

        $tokenColumns = [];

        foreach (UsageKind::cases() as $kind) {
            $tokenColumns[$kind->tokenColumn()] = $units->tokens($kind);
        }

        $usage->forceFill([
            'status' => UsageStatus::Settled,
            'units' => $units->tokens,
            'rates' => $rates->toArray(),
            ...$tokenColumns,
            'provider_cost_nanos' => $cost->toInt(),
            'charge_nanos' => $charge->toInt(),
            'flags' => array_values(array_unique($flags)),
            'external_id' => $externalId ?? $usage->external_id,
            // Stored in UTC, the same instant billing_date is derived from.
            'completed_at' => $completedAt->utc(),
            'billing_date' => $completedAt->utc()->toDateString(),
        ])->save();

        // Zero tokens ⇒ zero charge ⇒ no entry (a charge row is always < 0).
        // The partial unique index on charge.usage_record_id is the backstop.
        if (! $charge->isZero()) {
            $this->recordLedgerEntry->execute(
                customer: $customer,
                type: LedgerEntryType::Charge,
                amount: Nanodollars::zero()->minus($charge),
                usage: $usage,
            );
        }

        return $usage;
    }
}
