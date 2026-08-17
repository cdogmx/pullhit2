<?php

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['valuation.ebay.enabled' => true, 'valuation.ebay.view_refresh_hours' => 12]));

test('the job skips (no Oxylabs call) when the card was refreshed within the window', function () {
    Http::fake();
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(2)]);

    (new RefreshEbaySoldComps($item->id))->handle(app(IngestEbaySoldComps::class), app(OxylabsClient::class));

    Http::assertNothingSent();
});

test('force bypasses the freshness window (admin override)', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => '<ul></ul>']]], 200),
    ]);
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(2)]);

    (new RefreshEbaySoldComps($item->id, force: true))->handle(app(IngestEbaySoldComps::class), app(OxylabsClient::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oxylabs.io'));
});

test('the job fetches when the card is stale beyond the window', function () {
    // An EXPLICIT zero — eBay saying it found nothing. A bare "<ul></ul>" is not
    // that: it is indistinguishable from a degraded render, and the source
    // deliberately refuses to cache it (see the next test).
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => '<ul></ul><span>0 results</span>']]], 200),
    ]);
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(13)]);

    (new RefreshEbaySoldComps($item->id))->handle(app(IngestEbaySoldComps::class), app(OxylabsClient::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oxylabs.io'));
    expect($item->fresh()->ebay_refreshed_at->isToday())->toBeTrue();
});

test('a blank page that never says "no matches" is not cached as a zero', function () {
    // The behaviour that made the test above fail, and which nothing guarded.
    // eBay/Oxylabs intermittently return a results shell with no rendered cards;
    // stamping ebay_refreshed_at there would hold a false "no comps" for the
    // whole freshness window instead of retrying on the next view.
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => '<ul></ul>']]], 200),
    ]);
    $stale = now()->subHours(13);
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => $stale]);

    (new RefreshEbaySoldComps($item->id))->handle(app(IngestEbaySoldComps::class), app(OxylabsClient::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oxylabs.io'));
    expect($item->fresh()->ebay_refreshed_at->timestamp)->toBe($stale->timestamp);
});
