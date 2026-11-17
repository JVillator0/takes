<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\DemoController;
use App\Http\Controllers\Dev\ComponentGalleryController;
use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

// "Try a demo account" (D-045): a fresh ephemeral customer, throttled per IP.
Route::post('demo', [DemoController::class, 'store'])
    ->middleware(['guest:customer', 'throttle:demo'])
    ->name('demo.store');

// Customer area (D-032): `customer` group = customer.guard + auth:customer + customer.demo.
Route::middleware('customer')->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// Dev-only component gallery; the controller returns 404 outside local and testing.
Route::get('_ui', ComponentGalleryController::class)->name('dev.gallery');

require __DIR__.'/takes.php';
require __DIR__.'/settings.php';
require __DIR__.'/billing.php';
