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
 * per customer. Called by spec 4 on registration and for demo accounts.
 * Granting twice returns the first grant; the partial unique index
 * `ledger_entries_welcome_grant_unique` is the database backstop.
 */
final readonly class GrantWelcomeCreditAction
{
    public const string REASON = 'welcome';

    /** $1.00 until spec 3-01 adds `takes.welcome_credit_nanos`. */
    private const int DEFAULT_NANOS = Nanodollars::PER_DOLLAR;

    public function __construct(private RecordLedgerEntryAction $recordLedgerEntry) {}

    public function execute(Customer $customer): LedgerEntry
    {
        return DB::transaction(function () use ($customer): LedgerEntry {
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

            /** @var int $amount */
            $amount = config('takes.welcome_credit_nanos', self::DEFAULT_NANOS);

            return $this->recordLedgerEntry->execute(
                customer: $customer,
                type: LedgerEntryType::PromoGrant,
                amount: Nanodollars::fromInt($amount),
                reason: self::REASON,
            );
        });
    }
}
