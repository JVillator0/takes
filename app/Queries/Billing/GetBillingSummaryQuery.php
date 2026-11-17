<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\DTOs\Billing\BillingSummaryDTO;
use App\Enums\Billing\LedgerEntryType;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\Suggestion;
use App\Models\Take;
use Carbon\CarbonImmutable;
use UnexpectedValueException;

/**
 * The billing page cards (spec 6, P08). Balance and on hold are the cached
 * columns (D-024). "Spent this month" sums `charge` entries by the ledger's
 * `created_at` UTC month (the settlement date), so it reconciles to the
 * balance; it is split by feature (images, AI suggestions), never by
 * provider (D-023). Admin reports bucket by provider billing day instead.
 */
final readonly class GetBillingSummaryQuery
{
    public function execute(Customer $customer, ?CarbonImmutable $now = null): BillingSummaryDTO
    {
        $monthStart = ($now ?? CarbonImmutable::now())->utc()->startOfMonth();

        $spent = LedgerEntry::query()
            ->join('usage_records', 'usage_records.id', '=', 'ledger_entries.usage_record_id')
            ->where('ledger_entries.customer_id', $customer->id)
            ->where('ledger_entries.type', LedgerEntryType::Charge->value)
            ->where('ledger_entries.created_at', '>=', $monthStart)
            ->where('ledger_entries.created_at', '<', $monthStart->addMonth())
            // Charges are negative; spend is shown positive.
            ->selectRaw('COALESCE(-SUM(ledger_entries.amount_nanos) FILTER (WHERE usage_records.billable_type = ?), 0) AS images', [(new Take)->getMorphClass()])
            ->selectRaw('COALESCE(-SUM(ledger_entries.amount_nanos) FILTER (WHERE usage_records.billable_type = ?), 0) AS ai_suggestions', [(new Suggestion)->getMorphClass()])
            ->toBase()
            ->first();

        return BillingSummaryDTO::fromArray([
            'balance' => $customer->balance(),
            'held' => $customer->held(),
            'spent_images_nanos' => self::integer($spent?->images),
            'spent_ai_suggestions_nanos' => self::integer($spent?->ai_suggestions),
            'month_start' => $monthStart,
        ]);
    }

    /** Postgres `sum(bigint)` is numeric: PDO returns an integer string (money rule M-04). */
    private static function integer(mixed $sum): int|string
    {
        return is_int($sum) || is_string($sum)
            ? $sum
            : throw new UnexpectedValueException('A nanodollar sum must be an integer or an integer string.');
    }
}
