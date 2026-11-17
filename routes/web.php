<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

// Customer area (D-032): `customer` group = customer.guard + auth:customer + customer.demo.
Route::middleware('customer')->group(function (): void {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
