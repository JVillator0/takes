<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
| Customer billing routes (spec 3-03 issue 6), in the `customer` group
| (D-032: customer guard as the request default, auth:customer, demo expiry).
*/
Route::middleware('customer')->group(function (): void {
    Route::post('billing/purchases', [PurchaseController::class, 'store'])
        // Own prefix: the bare throttle key is the user id alone, which customers
        // and admin users (both bigint ids) would share with other limits.
        ->middleware('throttle:5,1,purchases')
        ->name('billing.purchases.store');
});
