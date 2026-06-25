<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Auth\UsernameController;
use App\Http\Controllers\Web\CatalogController;
use App\Http\Controllers\Web\CollectionController;
use App\Http\Controllers\Web\CollectionsController;
use App\Http\Controllers\Web\ContributeController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\FeedController;
use App\Http\Controllers\Web\FollowController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\RankingsController;
use App\Http\Controllers\Web\ScanController;
use App\Http\Controllers\Web\SearchController;
use App\Http\Controllers\Web\SitemapController;
use App\Http\Controllers\Web\SuggestionController;
use App\Http\Controllers\Web\UserProfileController;
use App\Http\Controllers\Web\WishlistController;
use App\Http\Controllers\Web\WishlistsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::inertia('brand', 'brand')->name('brand');
Route::inertia('terms', 'terms')->name('terms');
Route::inertia('privacy', 'privacy')->name('privacy');

// Social login (Laravel Socialite) — provider-agnostic; Facebook live, Google
// ready. Registered before the catch-all card route so /auth/* wins.
Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])
    ->where('provider', 'facebook|google')->name('oauth.redirect');
Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])
    ->where('provider', 'facebook|google')->name('oauth.callback');

// One-time username picker for social signups (no username yet).
Route::middleware('auth')->group(function () {
    Route::get('choose-username', [UsernameController::class, 'edit'])->name('username.edit');
    Route::post('choose-username', [UsernameController::class, 'store'])->name('username.store');
});

// Public catalog browse/search.
Route::get('browse', [CatalogController::class, 'index'])->name('catalog.browse');
// Header type-ahead — grouped brand/set/card suggestions (JSON).
Route::get('search/suggest', [SearchController::class, 'suggest'])->name('search.suggest');
// SEO landings: /browse/pokemon, /browse/pokemon/surging-sparks.
Route::get('browse/{productLine}', [CatalogController::class, 'browseLine'])->name('catalog.browse.line');
Route::get('browse/{productLine}/{set}', [CatalogController::class, 'browseSet'])->name('catalog.browse.set');
Route::get('catalog/{catalogItem}', [CatalogController::class, 'show'])->name('catalog.show');

// XML sitemaps (index → pages + chunked card sitemaps) for search engines.
Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('sitemap-cards-{page}.xml', [SitemapController::class, 'cards'])
    ->where('page', '[0-9]+')->name('sitemap.cards');

// robots.txt as a route (no static file) so the Sitemap line always points at
// the current host — cardfoo.com in production — via url().
Route::get('robots.txt', function () {
    $body = "User-agent: *\nDisallow:\n\nSitemap: ".url('/sitemap.xml')."\n";

    return response($body, 200)->header('Content-Type', 'text/plain');
})->name('robots');

// Public community rankings (leaderboard + monthly giveaway entries).
Route::get('rankings', RankingsController::class)->name('rankings');

// Public user profile (community face of an account) + follow graph.
Route::get('u/{username}', [UserProfileController::class, 'show'])->name('profile.show');
Route::get('u/{username}/followers', [FollowController::class, 'index'])
    ->defaults('type', 'followers')->name('profile.followers');
Route::get('u/{username}/following', [FollowController::class, 'index'])
    ->defaults('type', 'following')->name('profile.following');
Route::middleware('auth')->group(function () {
    Route::post('u/{username}/follow', [FollowController::class, 'store'])->name('profile.follow');
    Route::delete('u/{username}/follow', [FollowController::class, 'destroy'])->name('profile.unfollow');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Following feed — recent activity from people you follow.
    Route::get('feed', [FeedController::class, 'index'])->name('feed.index');

    // Collection + portfolio (always free for logged-in users).
    Route::get('collection', [CollectionController::class, 'index'])->name('collection.index');
    Route::get('collection/targets', [CollectionController::class, 'targets'])->name('collection.targets');
    Route::get('collection/export', [CollectionController::class, 'export'])->name('collection.export');
    Route::get('collection/import', [CollectionController::class, 'importForm'])->name('collection.import');
    Route::post('collection/import/preview', [CollectionController::class, 'importPreview'])->name('collection.import.preview');
    Route::post('collection/import', [CollectionController::class, 'importStore'])->name('collection.import.store');
    Route::post('collection', [CollectionController::class, 'store'])->name('collection.store');
    Route::patch('collection/folders/{collectionFolder}', [CollectionController::class, 'updateFolder'])->name('collection.folders.update');
    Route::patch('collection/{collectionItem}', [CollectionController::class, 'update'])->name('collection.update');
    Route::delete('collection/{collectionItem}', [CollectionController::class, 'destroy'])->name('collection.destroy');

    // Named collections (create / rename / share / delete), tier-gated.
    Route::post('collections', [CollectionsController::class, 'store'])->name('collections.store');
    Route::patch('collections/{collection}', [CollectionsController::class, 'update'])->name('collections.update');
    Route::delete('collections/{collection}', [CollectionsController::class, 'destroy'])->name('collections.destroy');

    // In-app notification center.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{notification}', [NotificationController::class, 'show'])->name('notifications.show');

    // Wishlist — cards a user wants, with optional target prices.
    Route::get('wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::get('wishlist/targets', [WishlistController::class, 'targets'])->name('wishlist.targets');
    Route::post('wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::patch('wishlist/{wishlistItem}', [WishlistController::class, 'update'])->name('wishlist.update');
    Route::delete('wishlist/{catalogItem}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

    // Named wishlists (create / rename / share / delete), tier-gated.
    Route::post('wishlists', [WishlistsController::class, 'store'])->name('wishlists.store');
    Route::patch('wishlists/{wishlist}', [WishlistsController::class, 'update'])->name('wishlists.update');
    Route::delete('wishlists/{wishlist}', [WishlistsController::class, 'destroy'])->name('wishlists.destroy');

    // Community contributions — report a missing card/set, see your standing.
    Route::get('contribute', [ContributeController::class, 'index'])->name('contribute.index');
    Route::post('contribute', [ContributeController::class, 'store'])->name('contribute.store');

    // Card scanner (Claude vision).
    Route::get('scan', [ScanController::class, 'index'])->name('scan.index');
    Route::post('scan', [ScanController::class, 'scan'])->name('scan.scan');
    Route::post('scan/confirm', [ScanController::class, 'confirm'])->name('scan.confirm');
    Route::post('scan/feedback', [ScanController::class, 'feedback'])->name('scan.feedback');
    Route::get('scan/history', [ScanController::class, 'history'])->name('scan.history');
    Route::get('scan/search', [ScanController::class, 'search'])->name('scan.search');

    // Suggest an edit to a catalog item (queued for admin review).
    Route::post('catalog/{catalogItem}/suggestions', [SuggestionController::class, 'store'])->name('catalog.suggest');
});

// Public, shareable collection page. Registered AFTER the auth routes above so
// /collection, /collection/export, /collection/import keep precedence; only an
// unmatched handle (/collection/{username}) falls through to here.
Route::get('collection/{username}', [CollectionController::class, 'publicShow'])->name('collection.public');
Route::get('collection/{username}/{collectionSlug}/folder/{folderSlug}', [CollectionController::class, 'publicFolder'])->name('collection.public.folder');
Route::get('collection/{username}/{collectionSlug}', [CollectionController::class, 'publicShowCollection'])->name('collection.public.named');
Route::get('wishlist/{username}', [WishlistController::class, 'publicShow'])->name('wishlist.public');
Route::get('wishlist/{username}/{wishlistSlug}', [WishlistController::class, 'publicShowWishlist'])->name('wishlist.public.named');

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';

// Canonical card page at /{brand}/{set}/{card-slug}. Registered LAST so it only
// catches 3-segment paths that no earlier (literal-prefixed) route claimed. The
// slug constraint keeps it from swallowing dotted/static paths.
Route::get('{productLine}/{set}/{card}', [CatalogController::class, 'showBySlug'])
    ->where(['productLine' => '[a-z0-9-]+', 'set' => '[a-z0-9-]+', 'card' => '[a-z0-9-]+'])
    ->name('catalog.card');
