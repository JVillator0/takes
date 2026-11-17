<?php

declare(strict_types=1);

use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Takes\CancelTakeController;
use App\Http\Controllers\Takes\EstimateController;
use App\Http\Controllers\Takes\TakeController;
use App\Http\Controllers\Takes\TakeImageController;
use Illuminate\Support\Facades\Route;

/*
| Projects and takes (spec 4 "Routes"). Customer guard only (D-032).
| Another customer's project, take, image or cancel is a 404 (2-02); only
| creating a take in someone else's project (or from their take) is a 403.
*/
Route::middleware('customer')->group(function (): void {
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::get('estimates/take', EstimateController::class)
        ->middleware('throttle:120,1')
        ->name('estimates.take');

    Route::post('projects/{project}/takes', [TakeController::class, 'store'])
        ->middleware('throttle:takes')
        ->name('takes.store');

    Route::get('takes/{take}', [TakeController::class, 'show'])->name('takes.show');
    Route::get('takes/{take}/image', TakeImageController::class)->name('takes.image');
    Route::post('takes/{take}/cancel', CancelTakeController::class)->name('takes.cancel');
});
