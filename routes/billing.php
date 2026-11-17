<?php

declare(strict_types=1);

use App\Http\Controllers\Billing\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
| Customer billing routes (spec 3-03 issue 6). The `customer` guard and the
| D-032 `Auth::shouldUse('customer')` group come with spec 3-01; this group
| moves into it then.
*/
Route::middleware(['auth:customer'])->group(function (): void {
    Route::post('billing/purchases', [PurchaseController::class, 'store'])
        // Own prefix: the bare throttle key is the user id alone, which customers
        // and admin users (both bigint ids) would share with other limits.
        ->middleware('throttle:5,1,purchases')
        ->name('billing.purchases.store');
});
