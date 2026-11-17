<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ShowBillingRequest;
use App\Http\Resources\Billing\BillingActivityRowResource;
use App\Http\Resources\Billing\BillingSummaryResource;
use App\Http\Resources\Billing\CreditPackResource;
use App\Http\Resources\Billing\InvoiceResource;
use App\Queries\Billing\GetBillingSummaryQuery;
use App\Queries\Billing\ListBillingActivityQuery;
use App\Queries\Billing\ListCreditPacksQuery;
use App\Queries\Billing\ListInvoicesQuery;
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
        ListCreditPacksQuery $packs,
    ): Response {
        $customer = $request->customer();
        $page = $activity->execute($customer, $request->integer('page', 1));

        return Inertia::render('billing/Show', [
            'summary' => (new BillingSummaryResource($summary->execute($customer)))->resolve($request),
            'packs' => CreditPackResource::collection($packs->execute())->resolve($request),
            'activity' => [
                'rows' => BillingActivityRowResource::collection($page->rows)->resolve($request),
                'page' => $page->page,
                'hasMore' => $page->hasMore,
            ],
            'invoices' => InvoiceResource::collection($invoices->execute($customer))->resolve($request),
        ]);
    }
}
