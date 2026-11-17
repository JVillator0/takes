<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

/**
 * Billing › Reports result: the rows (newest day first, or biggest spender first) and the totals row, computed by
 * its own SQL aggregate over the same filter, never by adding the formatted rows.
 *
 * Output-only (built by GetBillingReportQuery from typed values, never from an array): no `fromArray`
 * (architecture.md).
 */
final readonly class BillingReportDTO
{
    /**
     * @param  list<BillingReportRowDTO>  $rows
     */
    public function __construct(
        public array $rows,
        public BillingReportRowDTO $totals,
    ) {}
}
