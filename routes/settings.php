<?php

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/collection', [ProfileController::class, 'updateCollection'])->name('profile.collection');
    Route::post('settings/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar');
    Route::delete('settings/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');
    Route::patch('settings/notifications', [NotificationController::class, 'update'])->name('notifications.update');

    // Premium billing (Dodo Payments).
    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::post('settings/billing/checkout', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('settings/billing/credits', [BillingController::class, 'buyCredits'])->name('billing.credits');
    Route::delete('settings/billing', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::get('billing/return', fn () => redirect()->route('billing.edit')
        ->with('status', 'Your upgrade is processing — premium activates in a moment.'))->name('billing.return');
});
