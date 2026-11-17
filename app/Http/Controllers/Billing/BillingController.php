<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ShowBillingRequest;
use App\Http\Resources\Billing\BillingActivityRowResource;
use App\Http\Resources\Billing\BillingSummaryResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Queries\Billing\GetBillingSummaryQuery;
use App\Queries\Billing\ListBillingActivityQuery;
use App\Queries\Billing\ListInvoicesQuery;
use App\Support\Money\Nanodollars;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The billing page (spec 6, P08): Available, On hold, spend by feature,
 * credit packs, Activity and Invoices. A money page: exact amounts (D-042).
 */
final class BillingController extends Controller
{
    public function show(
        ShowBillingRequest $request,
        GetBillingSummaryQuery $summary,
        ListBillingActivityQuery $activity,
        ListInvoicesQuery $invoices,
    ): Response {
        $customer = $request->customer();
        $page = $activity->execute($customer, $request->integer('page', 1));

        return Inertia::render('billing/Show', [
            'summary' => (new BillingSummaryResource($summary->execute($customer)))->resolve($request),
            'packs' => array_map(fn (int $usd): array => [
                'usd' => $usd,
                'amount' => Nanodollars::fromInt($usd * Nanodollars::PER_DOLLAR)->formatExact(),
                // Simulated purchases carry no tax (spec 6); the dialog shows the line.
                'tax' => Nanodollars::zero()->formatExact(),
            ], array_values(Config::array('takes.purchase_packs_usd'))),
            'activity' => [
                'rows' => BillingActivityRowResource::collection($page->rows)->resolve($request),
                'page' => $page->page,
                'hasMore' => $page->hasMore,
            ],
            'invoices' => InvoiceResource::collection($invoices->execute($customer))->resolve($request),
        ]);
    }
}
