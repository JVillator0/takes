<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\LedgerEntryType;
use App\Exceptions\Billing\TransactionRequiredException;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\UsageRecord;
use App\Support\Money\Nanodollars;
use InvalidArgumentException;

/**
 * The only writer of `ledger_entries` and `customers.balance_nanos`
 * (spec 3-03 §6): it appends one real-money entry and moves the cached
 * balance by exactly that amount, under the customer lock, in the caller's
 * transaction. Holds are never ledger rows (D-024).
 */
final readonly class RecordLedgerEntryAction
{
    /**
     * @param  Nanodollars  $amount  signed: credits positive, charges negative
     */
    public function execute(
        Customer $customer,
        LedgerEntryType $type,
        Nanodollars $amount,
        ?UsageRecord $usage = null,
        ?Purchase $purchase = null,
        ?int $adminId = null,
        ?string $reason = null,
    ): LedgerEntry {
        TransactionRequiredException::unlessInTransaction(self::class);

        // Same rules as the table's CHECKs, failing before any write.
        $signIsValid = match ($type) {
            LedgerEntryType::Purchase, LedgerEntryType::PromoGrant => ! $amount->isNegative() && ! $amount->isZero(),
            LedgerEntryType::Charge => $amount->isNegative(),
            LedgerEntryType::Adjustment => ! $amount->isZero(),
        };

        if (! $signIsValid) {
            throw new InvalidArgumentException("Invalid amount {$amount->toDecimalString()} for a {$type->value} entry.");
        }

        if ($type === LedgerEntryType::Charge && $usage === null) {
            throw new InvalidArgumentException('A charge entry needs its usage record.');
        }

        if ($type === LedgerEntryType::Purchase && $purchase === null) {
            throw new InvalidArgumentException('A purchase entry needs its purchase.');
        }

        if (($usage !== null && $usage->customer_id !== $customer->id)
            || ($purchase !== null && $purchase->customer_id !== $customer->id)) {
            throw new InvalidArgumentException('The usage record or purchase belongs to another customer.');
        }

        // Lock order: the customer row is the only money lock (spec 3-03 §6).
        // Re-locking a row this transaction already holds is a no-op.
        $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

        $entry = new LedgerEntry;
        $entry->forceFill([
            'customer_id' => $locked->id,
            'type' => $type,
            'amount_nanos' => $amount->toInt(),
            'usage_record_id' => $usage?->id,
            'purchase_id' => $purchase?->id,
            'admin_id' => $adminId,
            'reason' => $reason,
        ])->save();

        // balance = Σ ledger (D-024), kept in step with the row just written.
        $locked->balance_nanos = $locked->balance()->plus($amount)->toInt();
        $locked->save();

        // Keep the caller's model in step without marking it dirty.
        $customer->balance_nanos = $locked->balance_nanos;
        $customer->syncOriginalAttribute('balance_nanos');

        return $entry;
    }
}
