<?php

use App\Http\Controllers\Api\V1\PingController;
use Illuminate\Support\Facades\Route;

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

    // Token-protected probe — confirms Sanctum bearer auth.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('user', [PingController::class, 'me'])->name('user');
    });
});
