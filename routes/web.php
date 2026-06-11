<?php

use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\CollectionController;
use App\Http\Controllers\Web\ScanController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('brand', 'brand')->name('brand');

// Public catalog browse/search.
Route::get('browse', [CatalogController::class, 'index'])->name('catalog.browse');
Route::get('catalog/{catalogItem}', [CatalogController::class, 'show'])->name('catalog.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Collection + portfolio (always free for logged-in users).
    Route::get('collection', [CollectionController::class, 'index'])->name('collection.index');
    Route::post('collection', [CollectionController::class, 'store'])->name('collection.store');
    Route::patch('collection/{collectionItem}', [CollectionController::class, 'update'])->name('collection.update');
    Route::delete('collection/{collectionItem}', [CollectionController::class, 'destroy'])->name('collection.destroy');

    // Card scanner (Claude vision).
    Route::get('scan', [ScanController::class, 'index'])->name('scan.index');
    Route::post('scan', [ScanController::class, 'scan'])->name('scan.scan');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
