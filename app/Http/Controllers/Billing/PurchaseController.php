<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\PurchaseCreditsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\StorePurchaseRequest;
use Illuminate\Http\RedirectResponse;

final class PurchaseController extends Controller
{
    /** Buy a credit pack (simulated payment, spec 3-03 §5). A replay is a no-op. */
    public function store(StorePurchaseRequest $request, PurchaseCreditsAction $purchaseCredits): RedirectResponse
    {
        $purchaseCredits->execute(
            $request->customer(),
            $request->integer('package'),
            $request->string('idempotency_key')->toString(),
        );

        return back();
    }
}
