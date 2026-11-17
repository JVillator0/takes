<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Billing\ExportBillingReportCsvAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportBillingReportRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Billing › Reports **Export CSV** as a real streamed download (spec 7, D-035): the rows go from the query cursor
 * straight to php://output, never buffered in a Livewire response.
 */
final class ExportBillingReportController extends Controller
{
    public function __invoke(ExportBillingReportRequest $request, ExportBillingReportCsvAction $export): StreamedResponse
    {
        $admin = $request->admin();
        $filter = $request->filter();
        $filename = sprintf('takes-report-%s-%s.csv', $filter->from->toDateString(), $filter->until->toDateString());

        return response()->streamDownload(function () use ($export, $admin, $filter): void {
            $out = fopen('php://output', 'wb');

            if ($out !== false) {
                $export->execute($admin, $filter, $out);
                fclose($out);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
