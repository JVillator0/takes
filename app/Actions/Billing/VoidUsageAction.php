<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\UsageStatus;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;

/**
 * Provider work failed or was rejected: release the hold, charge nothing
 * (failed = free, spec 3-03 §3 and §6). Voiding a usage that is not open is a
 * no-op, so a void can never undo a settled charge.
 */
final readonly class VoidUsageAction
{
    /**
     * @return UsageRecord the usage as re-read under the lock
     */
    public function execute(UsageRecord $usage): UsageRecord
    {
        TransactionRequiredException::unlessInTransaction(self::class);

        // The customer row is the only money lock; the usage is re-read under it.
        $customer = Customer::query()->lockForUpdate()->findOrFail($usage->customer_id);
        $usage = UsageRecord::query()->findOrFail($usage->id);

        if ($usage->status !== UsageStatus::Open) {
            return $usage;
        }

        $customer->held_nanos = $customer->held()->minus(Nanodollars::fromInt($usage->max_charge_nanos))->toInt();
        $customer->save();

        $usage->forceFill([
            'status' => UsageStatus::Voided,
            'provider_cost_nanos' => 0,
            'charge_nanos' => 0,
        ])->save();

        return $usage;
    }
}
