<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\PurchaseCreditsAction;
use App\Exceptions\Billing\IdempotencyKeyReusedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePurchaseRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class PurchaseController extends Controller
{
    /**
     * Buy a credit pack (simulated payment, spec 3-03 §5) and issue its
     * invoice (spec 6). A replay is a no-op that answers like the original;
     * the same key with another pack is a 409. Back on the page, the shared
     * balance props are fresh, and the toast links to the invoice.
     */
    public function store(StorePurchaseRequest $request, PurchaseCreditsAction $purchaseCredits): RedirectResponse
    {
        try {
            $purchase = $purchaseCredits->execute(
                $request->customer(),
                $request->integer('package'),
                $request->string('idempotency_key')->toString(),
            );
        } catch (IdempotencyKeyReusedException $e) {
            abort(409, $e->getMessage());
        }

        $invoice = $purchase->invoice;

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('billing.purchase_success'),
            'action' => $invoice === null ? null : [
                'label' => __('billing.view_invoice'),
                'href' => route('billing.invoices.show', $invoice),
            ],
        ]);

        return back();
    }
}
