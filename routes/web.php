<?php

use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\CollectionController;
use App\Http\Controllers\Web\ScanController;
use App\Http\Controllers\Web\WishlistController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('brand', 'brand')->name('brand');
Route::inertia('terms', 'terms')->name('terms');
Route::inertia('privacy', 'privacy')->name('privacy');

// Public catalog browse/search.
Route::get('browse', [CatalogController::class, 'index'])->name('catalog.browse');
// SEO landings: /browse/pokemon, /browse/pokemon/surging-sparks.
Route::get('browse/{productLine}', [CatalogController::class, 'browseLine'])->name('catalog.browse.line');
Route::get('browse/{productLine}/{set}', [CatalogController::class, 'browseSet'])->name('catalog.browse.set');
Route::get('catalog/{catalogItem}', [CatalogController::class, 'show'])->name('catalog.show');

Route::get('sitemap.xml', \App\Http\Controllers\Web\SitemapController::class)->name('sitemap');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    // Collection + portfolio (always free for logged-in users).
    Route::get('collection', [CollectionController::class, 'index'])->name('collection.index');
    Route::get('collection/export', [CollectionController::class, 'export'])->name('collection.export');
    Route::get('collection/import', [CollectionController::class, 'importForm'])->name('collection.import');
    Route::post('collection/import/preview', [CollectionController::class, 'importPreview'])->name('collection.import.preview');
    Route::post('collection/import', [CollectionController::class, 'importStore'])->name('collection.import.store');
    Route::post('collection', [CollectionController::class, 'store'])->name('collection.store');
    Route::patch('collection/{collectionItem}', [CollectionController::class, 'update'])->name('collection.update');
    Route::delete('collection/{collectionItem}', [CollectionController::class, 'destroy'])->name('collection.destroy');

    // Wishlist — cards a user wants, with optional target prices.
    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::patch('wishlist/{wishlistItem}', [WishlistController::class, 'update'])->name('wishlist.update');
    Route::delete('wishlist/{catalogItem}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

    // Card scanner (Claude vision).
    Route::get('scan', [ScanController::class, 'index'])->name('scan.index');
    Route::post('scan', [ScanController::class, 'scan'])->name('scan.scan');
});

// Public, shareable collection page. Registered AFTER the auth routes above so
// /collection, /collection/export, /collection/import keep precedence; only an
// unmatched handle (/collection/{username}) falls through to here.
Route::get('collection/{username}', [CollectionController::class, 'publicShow'])->name('collection.public');
Route::get('wishlist/{username}', [WishlistController::class, 'publicShow'])->name('wishlist.public');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
