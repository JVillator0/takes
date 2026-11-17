<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
| Customer billing routes (spec 3-03 issue 6), in the customer group (D-032).
| Spec 4's Buy credits modal posts here (D-026).
*/
Route::middleware('customer')->group(function (): void {
    Route::post('billing/purchases', [PurchaseController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('billing.purchases.store');
});
