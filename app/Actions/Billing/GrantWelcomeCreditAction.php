<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\DB;

/**
 * The welcome credit (D-019, D-026): one `promo_grant` with reason `welcome`
 * per customer. Built here, ahead of spec 4, which calls it on registration
 * and for demo accounts (it needs the ledger writer and the unique index).
 * Granting twice returns the first grant; the partial unique index
 * `ledger_entries_welcome_grant_unique` is the database backstop.
 */
final readonly class GrantWelcomeCreditAction
{
    public const string REASON = 'welcome';

    /** Fallback if `takes.welcome_credit_nanos` (spec 3-01, default $1.00) is unset. */
    private const int DEFAULT_NANOS = Nanodollars::PER_DOLLAR;

    public function __construct(private RecordLedgerEntryAction $recordLedgerEntry) {}

    /**
     * @return LedgerEntry|null the grant, or null when the welcome credit is configured as 0
     */
    public function execute(Customer $customer): ?LedgerEntry
    {
        /** @var int $configured */
        $configured = config('takes.welcome_credit_nanos', self::DEFAULT_NANOS);
        $amount = Nanodollars::fromInt($configured);

        // A zero welcome credit switches the grant off; it is not an error.
        if ($amount->isZero()) {
            return null;
        }

        return DB::transaction(function () use ($customer, $amount): LedgerEntry {
            // Under the customer lock, so two grants cannot race.
            Customer::query()->lockForUpdate()->findOrFail($customer->id);

            $existing = LedgerEntry::query()
                ->where('customer_id', $customer->id)
                ->where('type', LedgerEntryType::PromoGrant)
                ->where('reason', self::REASON)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->recordLedgerEntry->execute(
                customer: $customer,
                type: LedgerEntryType::PromoGrant,
                amount: $amount,
                reason: self::REASON,
            );
        });
    }
}
