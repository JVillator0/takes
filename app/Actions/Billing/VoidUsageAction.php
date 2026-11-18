<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\DTOs\Billing\AbsorbedUsageDTO;
use App\Enums\Billing\UsageKind;
use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use App\Support\Money\TokenCost;

/**
 * Provider work failed or was rejected: release the hold, charge nothing
 * (failed = free, spec 3-03 §3 and §6). Voiding a usage that is not open
 * changes no money, so a void can never undo a settled charge (M-12).
 *
 * When the provider still billed the failed call (an unusable AI response),
 * the caller passes what it consumed: the voided usage keeps those units and
 * their cost as `absorbed_cost_nanos`, which the global daily AI budget counts
 * (spec 8 Guardrails, D-087). Cost and charge stay 0: the customer is never
 * charged. The one write on a non-open usage: a voided usage with no absorbed
 * cost yet records it (an AI result that came back after expiry); a second
 * absorb, or any absorb on a settled usage, is a no-op.
 */
final readonly class VoidUsageAction
{
    public function __construct(private TokenCost $tokenCost) {}

    /**
     * @param  AbsorbedUsageDTO|null  $absorbed  units the provider billed for this failed work, if any
     * @return UsageRecord the usage as re-read under the lock
     */
    public function execute(UsageRecord $usage, ?AbsorbedUsageDTO $absorbed = null): UsageRecord
    {
        TransactionRequiredException::unlessInTransaction(self::class);

        // The customer row is the only money lock; the usage is re-read under it.
        $customer = Customer::query()->lockForUpdate()->findOrFail($usage->customer_id);
        $usage = UsageRecord::query()->findOrFail($usage->id);

        if ($usage->status === UsageStatus::Open) {
            $customer->held_nanos = $customer->held()->minus(Nanodollars::fromInt($usage->max_charge_nanos))->toInt();
            $customer->save();

            $usage->forceFill([
                'status' => UsageStatus::Voided,
                'provider_cost_nanos' => 0,
                'charge_nanos' => 0,
            ]);
        }

        // Also for a usage voided earlier (an AI result that came back after
        // its suggestion expired): recorded once, never on a settled usage.
        if ($absorbed !== null && $usage->status === UsageStatus::Voided && $usage->absorbed_cost_nanos === null) {
            $this->absorb($usage, $customer, $absorbed);
        }

        if ($usage->isDirty()) {
            $usage->save();
        }

        return $usage;
    }

    private function absorb(UsageRecord $usage, Customer $customer, AbsorbedUsageDTO $absorbed): void
    {
        $cost = $this->tokenCost->compute(
            $absorbed->units->tokens,
            $absorbed->rates->toArray(),
            ['usage_record_id' => $usage->id, 'customer_id' => $customer->id],
        );

        $tokenColumns = [];

        foreach (UsageKind::cases() as $kind) {
            $tokenColumns[$kind->tokenColumn()] = $absorbed->units->tokens($kind);
        }

        $usage->forceFill([
            'units' => $absorbed->units->tokens,
            'rates' => $absorbed->rates->toArray(),
            ...$tokenColumns,
            'absorbed_cost_nanos' => $cost->toInt(),
            'external_id' => $absorbed->externalId ?? $usage->external_id,
            // The call's UTC day, as for an orphan success (D-087): reports and
            // the Pixelforge check read only settled rows on a billing day.
            'completed_at' => $absorbed->completedAt->utc(),
            'billing_date' => $absorbed->completedAt->utc()->toDateString(),
        ]);
    }
}
