<?php

use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\CardController;
use App\Http\Controllers\Admin\CardReportController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EbaySweepController;
use App\Http\Controllers\Admin\GiveawayController;
use App\Http\Controllers\Admin\ImageController;
use App\Http\Controllers\Admin\ReconcileController;
use App\Http\Controllers\Admin\ScanFeedbackController;
use App\Http\Controllers\Admin\SetController;
use App\Http\Controllers\Admin\StockAlertController;
use App\Http\Controllers\Admin\StructureController;
use App\Http\Controllers\Admin\SuggestionController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
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

    // Brands (product lines) — create, edit (logo + description), delete (cascades).
    Route::get('brands', [BrandController::class, 'index'])->name('brands.index');
    Route::post('brands', [BrandController::class, 'store'])->name('brands.store');
    Route::patch('brands/{productLine}', [BrandController::class, 'update'])->name('brands.update');
    Route::delete('brands/{productLine}', [BrandController::class, 'destroy'])->name('brands.destroy');

    // Sets
    Route::get('sets', [SetController::class, 'index'])->name('sets.index');
    Route::post('sets', [SetController::class, 'store'])->name('sets.store');
    Route::patch('sets/{set}', [SetController::class, 'update'])->name('sets.update');
    Route::delete('sets/{set}', [SetController::class, 'destroy'])->name('sets.destroy');
    Route::get('sets/search', [SetController::class, 'search'])->name('sets.search');
    Route::post('sets/import', [SetController::class, 'import'])->name('sets.import');
    Route::post('sets/{set}/resync', [SetController::class, 'resync'])->name('sets.resync');
    Route::post('sets/{set}/sealed', [SetController::class, 'storeSealed'])->name('sets.sealed');
    Route::patch('sealed/{catalogItem}', [SetController::class, 'updateSealed'])->name('sealed.update');
    Route::get('sets/{set}/missing', [SetController::class, 'missing'])->name('sets.missing');

    // Catalog structure reference (brand → series → set → subset → card).
    Route::get('structure', [StructureController::class, 'index'])->name('structure');
    Route::post('structure/rename-series', [StructureController::class, 'renameSeries'])->name('structure.rename-series');

    // PriceCharting reconciliation review queue
    Route::get('reconcile', [ReconcileController::class, 'index'])->name('reconcile.index');
    Route::get('reconcile/{set}/changes', [ReconcileController::class, 'changes'])->name('reconcile.changes');
    Route::post('reconcile/approve-batch', [ReconcileController::class, 'approveBatch'])->name('reconcile.approveBatch');
    Route::post('reconcile/{change}/approve', [ReconcileController::class, 'approve'])->name('reconcile.approve');
    Route::post('reconcile/{change}/skip', [ReconcileController::class, 'skip'])->name('reconcile.skip');

    // User-submitted edit suggestions (review queue)
    Route::get('suggestions', [SuggestionController::class, 'index'])->name('suggestions.index');
    Route::post('suggestions/{itemEditSuggestion}/approve', [SuggestionController::class, 'approve'])->name('suggestions.approve');
    Route::post('suggestions/{itemEditSuggestion}/reject', [SuggestionController::class, 'reject'])->name('suggestions.reject');

    // Users — roster + account management (tier, admin, credits, ban, cancel).
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/credits', [UserController::class, 'credits'])->name('users.credits');
    Route::post('users/{user}/cancel', [UserController::class, 'cancel'])->name('users.cancel');
    Route::post('users/{user}/ban', [UserController::class, 'ban'])->name('users.ban');
    Route::post('users/{user}/unban', [UserController::class, 'unban'])->name('users.unban');

    // Billing ledger — all recorded transactions (read-only).
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');

    // Scan-detection feedback review (cache vs AI accuracy).
    Route::get('scan-feedback', [ScanFeedbackController::class, 'index'])->name('scan-feedback.index');

    // Community: review user "missing card/set" reports (accept awards points).
    Route::get('card-reports', [CardReportController::class, 'index'])->name('card-reports.index');
    Route::post('card-reports/{cardReport}/approve', [CardReportController::class, 'approve'])->name('card-reports.approve');
    Route::post('card-reports/{cardReport}/reject', [CardReportController::class, 'reject'])->name('card-reports.reject');

    // Monthly community giveaways — create + draw a weighted-random winner.
    Route::get('giveaways', [GiveawayController::class, 'index'])->name('giveaways.index');
    Route::post('giveaways', [GiveawayController::class, 'store'])->name('giveaways.store');
    Route::post('giveaways/{giveaway}/draw', [GiveawayController::class, 'draw'])->name('giveaways.draw');
    Route::delete('giveaways/{giveaway}', [GiveawayController::class, 'destroy'])->name('giveaways.destroy');

    // eBay sweep: watch broad sold-comp match quality (applied vs misses), and
    // reject / reassign applied sales (sticky across future re-pulls).
    Route::get('ebay-sweep', [EbaySweepController::class, 'index'])->name('ebay-sweep.index');
    Route::post('ebay-sweep/applied/{saleObservation}/reject', [EbaySweepController::class, 'reject'])->name('ebay-sweep.reject');
    Route::post('ebay-sweep/applied/{saleObservation}/reassign', [EbaySweepController::class, 'reassign'])->name('ebay-sweep.reassign');
    Route::post('ebay-sweep/misses/{ebaySweepMiss}/assign', [EbaySweepController::class, 'assign'])->name('ebay-sweep.assign');
    Route::post('ebay-sweep/misses/{ebaySweepMiss}/reject', [EbaySweepController::class, 'rejectMiss'])->name('ebay-sweep.reject-miss');

    // Stock alerts: products (optionally tied to a catalog item) with a target
    // price + many retailer links; tweets per retailer when in stock at/below.
    Route::get('stock-alerts', [StockAlertController::class, 'index'])->name('stock-alerts.index');
    Route::get('stock-alerts/catalog-search', [StockAlertController::class, 'catalogSearch'])->name('stock-alerts.catalog-search');
    Route::post('stock-alerts', [StockAlertController::class, 'store'])->name('stock-alerts.store');
    Route::patch('stock-alerts/{trackedProduct}', [StockAlertController::class, 'update'])->name('stock-alerts.update');
    Route::post('stock-alerts/{trackedProduct}/toggle', [StockAlertController::class, 'toggle'])->name('stock-alerts.toggle');
    Route::delete('stock-alerts/{trackedProduct}', [StockAlertController::class, 'destroy'])->name('stock-alerts.destroy');
    Route::post('stock-alerts/{trackedProduct}/links', [StockAlertController::class, 'storeLink'])->name('stock-alerts.links.store');
    Route::post('stock-alerts/links/{retailerLink}/toggle', [StockAlertController::class, 'toggleLink'])->name('stock-alerts.links.toggle');
    Route::post('stock-alerts/links/{retailerLink}/check', [StockAlertController::class, 'checkLink'])->name('stock-alerts.links.check');
    Route::delete('stock-alerts/links/{retailerLink}', [StockAlertController::class, 'destroyLink'])->name('stock-alerts.links.destroy');

    // Cards
    Route::get('cards', [CardController::class, 'index'])->name('cards.index');
    Route::post('cards', [CardController::class, 'store'])->name('cards.store');
    Route::post('cards/{catalogItem}/refresh', [CardController::class, 'refresh'])->name('cards.refresh');
    Route::patch('cards/{catalogItem}', [CardController::class, 'update'])->name('cards.update');
    Route::delete('cards/{catalogItem}', [CardController::class, 'destroy'])->name('cards.destroy');
});
