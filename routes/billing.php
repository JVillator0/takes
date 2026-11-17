<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
| Customer billing routes (spec 3-03 issue 6, spec 6), in the `customer`
| group: customer guard as the request default, auth:customer and the demo
| expiry check (D-032, D-065).
*/
Route::middleware('customer')->group(function (): void {
    Route::get('billing', [BillingController::class, 'show'])->name('billing.show');

    Route::post('billing/purchases', [PurchaseController::class, 'store'])
        // Own prefix: the bare throttle key is the user id alone, which customers
        // and admin users (both bigint ids) would share with other limits.
        ->middleware('throttle:5,1,purchases')
        ->name('billing.purchases.store');

    Route::get('billing/invoices/{invoice}', [InvoiceController::class, 'show'])->name('billing.invoices.show');
});
