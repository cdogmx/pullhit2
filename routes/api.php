<?php

use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\PingController;
use App\Http\Controllers\Api\V1\ScanController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Provider webhooks — stateless, signature-verified (no CSRF/session/auth).
Route::post('webhooks/dodo', [WebhookController::class, 'dodo'])->name('webhooks.dodo');

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| The single, versioned surface the future native app consumes. Every
| endpoint here calls the SAME Action classes the web controllers use —
| keep these controllers thin (see BUILD_PLAN §2/§12).
|
| Auth: session cookies serve the Inertia web app; personal-access tokens
| (Sanctum) serve API clients. Protect token-only routes with `auth:sanctum`.
|
*/

Route::prefix('v1')->middleware('throttle:api')->name('api.v1.')->group(function () {
    // Public liveness check.
    Route::get('ping', [PingController::class, 'show'])->name('ping');

    // Per-card reads the PUBLIC web pages fetch client-side for anonymous
    // visitors (the value poll, price-history chart, comps drawer, buy links).
    // These expose one card's data — the same data already rendered in its
    // public HTML page — so they stay public (throttled above).
    Route::get('catalog/{catalogItem}/values', [CatalogController::class, 'values'])->name('catalog.values');
    Route::get('catalog/{catalogItem}/listings', [CatalogController::class, 'listings'])->name('catalog.listings');
    Route::get('catalog/{catalogItem}/observations', [CatalogController::class, 'observations'])->name('catalog.observations');
    Route::get('catalog/{catalogItem}/price-history', [CatalogController::class, 'priceHistory'])->name('catalog.price-history');

    // Token-protected (Sanctum). The web app never calls these — the site browses
    // via server-rendered Inertia pages — so keeping the bulk catalog search + the
    // full item payload token-only prevents anonymous enumeration/scraping of the
    // whole catalog. Native/API clients authenticate.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [PingController::class, 'me'])->name('user');

        // Catalog browse/search + full item (native-app / external API surface).
        Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::get('catalog/{catalogItem}', [CatalogController::class, 'show'])->name('catalog.show');

        // Collection + portfolio (native-app parity with the web controller).
        Route::get('collection', [CollectionController::class, 'index'])->name('collection.index');
        Route::post('collection', [CollectionController::class, 'store'])->name('collection.store');
        Route::patch('collection/{collectionItem}', [CollectionController::class, 'update'])->name('collection.update');
        Route::delete('collection/{collectionItem}', [CollectionController::class, 'destroy'])->name('collection.destroy');

        // Card scanner (Claude vision).
        Route::post('scan', [ScanController::class, 'scan'])->name('scan.scan');
    });
});
