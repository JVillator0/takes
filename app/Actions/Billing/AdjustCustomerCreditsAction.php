<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Admin\RecordAdminActivityAction;
use App\Enums\Billing\LedgerEntryType;
use App\Exceptions\Billing\InsufficientCreditsException;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Customers › **Adjust credits** (A05, spec 7): one ledger `adjustment` row written by RecordLedgerEntryAction
 * (the only ledger writer) under the customer lock, with the admin and a required reason, plus an audit entry in
 * the same transaction. Never edits the balance directly. A negative adjustment may not take more than the
 * customer's available credit (balance − held, D-024): held money still backs open takes.
 */
final readonly class AdjustCustomerCreditsAction
{
    public const string EVENT = 'credits_adjusted';

    public function __construct(
        private RecordLedgerEntryAction $recordLedgerEntry,
        private RecordAdminActivityAction $recordActivity,
    ) {}

    public function execute(User $admin, Customer $customer, Nanodollars $amount, string $reason): LedgerEntry
    {
        $reason = trim($reason);

        if ($amount->isZero() || $reason === '') {
            throw new InvalidArgumentException('An adjustment needs a non-zero amount and a reason.');
        }

        return DB::transaction(function () use ($admin, $customer, $amount, $reason): LedgerEntry {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if ($amount->isNegative() && $locked->available()->plus($amount)->isNegative()) {
                throw new InsufficientCreditsException($locked->id, $locked->available(), Nanodollars::zero()->minus($amount));
            }

            $entry = $this->recordLedgerEntry->execute(
                $locked,
                LedgerEntryType::Adjustment,
                $amount,
                adminId: $admin->id,
                reason: $reason,
            );

            $this->recordActivity->execute($admin, self::EVENT, $locked, [
                'resource' => $locked->getTable(),
                'record' => (string) $locked->id,
                'ledger_entry_id' => $entry->id,
                'amount_nanos' => $amount->toInt(),
                'reason' => $reason,
            ]);

            $customer->balance_nanos = $locked->balance_nanos;
            $customer->syncOriginalAttribute('balance_nanos');

            return $entry;
        });
    }
}
