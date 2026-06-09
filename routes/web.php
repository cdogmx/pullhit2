<?php

use App\Http\Controllers\Web\CatalogController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public catalog browse/search.
Route::get('browse', [CatalogController::class, 'index'])->name('catalog.browse');
Route::get('catalog/{catalogItem}', [CatalogController::class, 'show'])->name('catalog.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
