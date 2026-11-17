<?php

declare(strict_types=1);

use App\Http\Controllers\Suggestions\SuggestionController;
use Illuminate\Support\Facades\Route;

/*
| Describe a change (spec 8). Customer area (D-032): `customer` group =
| customer.guard + auth:customer + customer.demo. Only the paid request is
| throttled (6/min/customer, `rate_limited`, D-050).
*/
Route::middleware('customer')->group(function (): void {
    Route::get('takes/{take}/suggestions/quote', [SuggestionController::class, 'quote'])->name('suggestions.quote');
    Route::post('takes/{take}/suggestions', [SuggestionController::class, 'store'])
        ->middleware('throttle:ai-suggestions')
        ->name('suggestions.store');
    Route::get('suggestions/{suggestion}', [SuggestionController::class, 'show'])->name('suggestions.show');
    Route::post('suggestions/{suggestion}/apply', [SuggestionController::class, 'apply'])->name('suggestions.apply');
    Route::post('suggestions/{suggestion}/discard', [SuggestionController::class, 'discard'])->name('suggestions.discard');
});
