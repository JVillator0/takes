<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The printable invoice (spec 6, P09): HTML with print CSS, "Print / Save as
 * PDF" through the browser, no PDF library. Owner only; another customer's
 * invoice is a 404 (InvoicePolicy).
 */
final class InvoiceController extends Controller
{
    public function show(Request $request, Invoice $invoice): Response
    {
        Gate::authorize('view', $invoice);

        $invoice->load('purchase');

        return Inertia::render('billing/Invoice', [
            'invoice' => (new InvoiceResource($invoice))->detail($request),
        ]);
    }
}
