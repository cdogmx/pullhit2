<?php

use App\Actions\Valuation\MaybeRefreshEbay;
use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use App\Models\Set;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    // These tests exercise staleness/dispatch logic — not the rarity gate.
    config(['valuation.ebay.skip_rarities' => []]);
});

test('it skips a low-value rarity even when stale', function () {
    config(['valuation.ebay.skip_rarities' => ['Common', 'Uncommon']]);
    $item = CatalogItem::factory()->create([
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'normal'],
    ]);

    expect(app(MaybeRefreshEbay::class)($item))->toBeFalse();
    Queue::assertNothingPushed();
});

test('it still refreshes a chase rarity', function () {
    config(['valuation.ebay.skip_rarities' => ['Common', 'Uncommon']]);
    $item = CatalogItem::factory()->create([
        'ebay_refreshed_at' => null,
        'attributes' => ['language' => 'en', 'rarity' => 'Special Illustration Rare', 'variant' => 'holo'],
    ]);

    expect(app(MaybeRefreshEbay::class)($item))->toBeTrue();
    Queue::assertPushed(RefreshEbaySoldComps::class);
});

test('it dispatches a refresh when the card has never been pulled', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null]);

    app(MaybeRefreshEbay::class)($item);

    Queue::assertPushed(RefreshEbaySoldComps::class);
});

test('it does not dispatch when refreshed within the last 12 hours', function () {
    $item = CatalogItem::factory()->create([
        'ebay_refreshed_at' => now()->subHours(3),
    ]);

    expect(app(MaybeRefreshEbay::class)($item))->toBeFalse();
    Queue::assertNothingPushed();
});

test('a card stale beyond 12 hours is refreshed (and reports refreshing)', function () {
    $item = CatalogItem::factory()->create([
        'ebay_refreshed_at' => now()->subHours(13),
    ]);

    expect(app(MaybeRefreshEbay::class)($item))->toBeTrue();
    Queue::assertPushed(RefreshEbaySoldComps::class);
});

test('it does nothing when eBay refresh is disabled', function () {
    config(['valuation.ebay.enabled' => false]);
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null]);

    app(MaybeRefreshEbay::class)($item);

    Queue::assertNothingPushed();
});

test('viewing a card increments popularity and queues a refresh when due', function () {
    // A consistent set/product-line so /catalog/{id} can 301 to the canonical
    // page (which is where the view side effects run); follow that redirect.
    $set = Set::factory()->create();
    $item = CatalogItem::factory()->create([
        'set_id' => $set->id, 'product_line_id' => $set->product_line_id,
        'ebay_refreshed_at' => null, 'popularity' => 0,
    ]);

    $this->followingRedirects()->get("/catalog/{$item->id}")->assertOk();

    expect($item->fresh()->popularity)->toBe(1);
    Queue::assertPushed(RefreshEbaySoldComps::class);
});

test('viewing a card that was refreshed long ago re-reads the date and works', function () {
    // Reproduces the live error: a DB-fetched item with a stored (string) date.
    $set = Set::factory()->create();
    $item = CatalogItem::factory()->create([
        'set_id' => $set->id, 'product_line_id' => $set->product_line_id,
        'ebay_refreshed_at' => now()->subDays(30),
        'popularity' => 0,
    ]);

    // Re-read so ebay_refreshed_at comes back through the cast (not in-memory Carbon).
    $fresh = CatalogItem::find($item->id);
    expect($fresh->ebay_refreshed_at)->toBeInstanceOf(CarbonInterface::class);

    $this->followingRedirects()->get("/catalog/{$item->id}")->assertOk();
    Queue::assertPushed(RefreshEbaySoldComps::class); // >12h stale
});
