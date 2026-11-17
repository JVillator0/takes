<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Admin\RecordAdminActivityAction;
use App\DTOs\Billing\BillingReportFilterDTO;
use App\Models\User;
use App\Queries\Billing\ListBillingReportExportRowsQuery;
use App\Support\Money\Nanodollars;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing › Reports **Export CSV** (spec 7, D-035, D-042): day × customer × provider rows, streamed. Money is an
 * exact decimal string (`0.074037500`) next to its integer nanodollars; a customer name that a spreadsheet would
 * run as a formula is prefixed with `'`. The export itself is audited.
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

    public function execute(User $admin, BillingReportFilterDTO $filter): StreamedResponse
    {
        $this->recordActivity->execute($admin, self::EVENT, null, [
            'page' => 'billing/reports',
            'from' => $filter->from->toDateString(),
            'until' => $filter->until->toDateString(),
        ]);

        $filename = sprintf('takes-report-%s-%s.csv', $filter->from->toDateString(), $filter->until->toDateString());

        return response()->streamDownload(function () use ($filter): void {
            $out = fopen('php://output', 'wb');

            if ($out === false) {
                return;
            }

            fputcsv($out, self::COLUMNS, escape: '');

            foreach ($this->rows->execute($filter) as $row) {
                $spend = Nanodollars::fromInt($row->spend_nanos);
                $cost = Nanodollars::fromInt($row->cost_nanos);
                $profit = $spend->minus($cost);

                fputcsv($out, [
                    $row->billing_date,
                    (string) $row->customer_id,
                    self::safeCell($row->customer_name),
                    $row->provider,
                    (string) $row->generations_charged,
                    $spend->toDecimalString(),
                    $cost->toDecimalString(),
                    $profit->toDecimalString(),
                    $spend->isZero() ? '' : (string) intdiv($profit->toInt() * 10_000, $spend->toInt()),
                    (string) $spend->toInt(),
                    (string) $cost->toInt(),
                    (string) $profit->toInt(),
                ], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** CSV formula injection: a cell starting with =, +, - or @ is read as text. */
    private static function safeCell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
