<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Admin\RecordAdminActivityAction;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\Models\User;
use App\Queries\Billing\ListBillingReportExportRowsQuery;
use App\Support\Money\BasisPoints;
use App\Support\Money\Nanodollars;

/**
 * Billing › Reports **Export CSV** (spec 7, D-035, D-042): day × customer × provider rows written to a stream
 * (never an HTTP response: the page wraps it in a streamed download). Money is an exact decimal string
 * (`0.074037500`) next to its integer nanodollars; a customer name that a spreadsheet would run as a formula is
 * prefixed with `'`. The export itself is audited with its filters.
 */
final readonly class ExportBillingReportCsvAction
{
    public const string EVENT = 'exported';

    /** @var list<string> */
    public const array COLUMNS = [
        'billing_date', 'customer_id', 'customer_name', 'provider', 'generations_charged',
        'spend', 'cost', 'profit', 'margin_bps', 'spend_nanos', 'cost_nanos', 'profit_nanos',
    ];

    public function __construct(
        private ListBillingReportExportRowsQuery $rows,
        private RecordAdminActivityAction $recordActivity,
    ) {}

    /**
     * Audits the export (who, range and filters) and writes the CSV to `$stream` (any writable stream: the
     * page passes php://output inside a streamed download; a command could pass a file). Returns the rows written.
     *
     * @param  resource  $stream
     */
    public function execute(User $admin, BillingReportFilterDTO $filter, $stream): int
    {
        $this->recordActivity->execute($admin, self::EVENT, null, [
            'page' => 'billing/reports',
            'from' => $filter->from->toDateString(),
            'until' => $filter->until->toDateString(),
            'customer_id' => $filter->customerId,
            'provider' => $filter->provider?->value,
        ]);

        fputcsv($stream, self::COLUMNS, escape: '');
        $written = 0;

        foreach ($this->rows->execute($filter) as $row) {
            $spend = Nanodollars::fromInt($row->spend_nanos);
            $cost = Nanodollars::fromInt($row->cost_nanos);
            $profit = $spend->minus($cost);

            fputcsv($stream, [
                $row->billing_date,
                (string) $row->customer_id,
                self::safeCell($row->customer_name),
                $row->provider,
                (string) $row->generations_charged,
                $spend->toDecimalString(),
                $cost->toDecimalString(),
                $profit->toDecimalString(),
                (string) (BasisPoints::ratio($profit->toInt(), $spend->toInt()) ?? ''),
                (string) $spend->toInt(),
                (string) $cost->toInt(),
                (string) $profit->toInt(),
            ], escape: '');
            $written++;
        }

        return $written;
    }

    /**
     * CSV formula injection: a cell starting with =, +, - or @ (spec 7), or a tab or carriage return (OWASP), is
     * read as text.
     */
    private static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
