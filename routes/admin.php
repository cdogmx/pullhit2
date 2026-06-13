<?php

use App\Http\Controllers\Admin\CardController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ImageController;
use App\Http\Controllers\Admin\SetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin area
|--------------------------------------------------------------------------
| Back-office for catalog operations — admins only (is_admin). Reuses the
| import + catalog Actions; controllers stay thin.
*/

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Image intake (file upload or URL → stored in our bucket, never hot-linked).
    Route::post('images', [ImageController::class, 'store'])->name('images.store');

    // Sets
    Route::get('sets', [SetController::class, 'index'])->name('sets.index');
    Route::get('sets/search', [SetController::class, 'search'])->name('sets.search');
    Route::post('sets/import', [SetController::class, 'import'])->name('sets.import');
    Route::post('sets/{set}/resync', [SetController::class, 'resync'])->name('sets.resync');
    Route::post('sets/{set}/sealed', [SetController::class, 'storeSealed'])->name('sets.sealed');
    Route::patch('sealed/{catalogItem}', [SetController::class, 'updateSealed'])->name('sealed.update');
    Route::get('sets/{set}/missing', [SetController::class, 'missing'])->name('sets.missing');

    // Cards
    Route::get('cards', [CardController::class, 'index'])->name('cards.index');
    Route::post('cards/{catalogItem}/refresh', [CardController::class, 'refresh'])->name('cards.refresh');
    Route::patch('cards/{catalogItem}', [CardController::class, 'update'])->name('cards.update');
    Route::delete('cards/{catalogItem}', [CardController::class, 'destroy'])->name('cards.destroy');
});
