<?php

use App\Http\Controllers\Dev\ComponentGalleryController;
use App\Http\Controllers\LandingController;
use Illuminate\Support\Facades\Route;

Route::get('/', LandingController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// Dev-only component gallery; the controller returns 404 outside local and testing.
Route::get('_ui', ComponentGalleryController::class)->name('dev.gallery');

require __DIR__.'/settings.php';
