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

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Public liveness check.
    Route::get('ping', [PingController::class, 'show'])->name('ping');

    // Public catalog browse/search.
    Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::get('catalog/{catalogItem}/observations', [CatalogController::class, 'observations'])->name('catalog.observations');
    Route::get('catalog/{catalogItem}', [CatalogController::class, 'show'])->name('catalog.show');

    // Token-protected probe — confirms Sanctum bearer auth.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [PingController::class, 'me'])->name('user');

        // Collection + portfolio (native-app parity with the web controller).
        Route::get('collection', [CollectionController::class, 'index'])->name('collection.index');
        Route::post('collection', [CollectionController::class, 'store'])->name('collection.store');
        Route::patch('collection/{collectionItem}', [CollectionController::class, 'update'])->name('collection.update');
        Route::delete('collection/{collectionItem}', [CollectionController::class, 'destroy'])->name('collection.destroy');

        // Card scanner (Claude vision).
        Route::post('scan', [ScanController::class, 'scan'])->name('scan.scan');
    });
});
