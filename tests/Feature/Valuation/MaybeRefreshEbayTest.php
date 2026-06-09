<?php

use App\Actions\Valuation\MaybeRefreshEbay;
use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('it dispatches a refresh when the card has never been pulled', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null]);

    app(MaybeRefreshEbay::class)($item);

    Queue::assertPushed(RefreshEbaySoldComps::class);
});

test('it does not dispatch when a cold card was refreshed recently', function () {
    $item = CatalogItem::factory()->create([
        'popularity' => 0,                       // cold tier => 14-day TTL
        'ebay_refreshed_at' => now()->subDay(),
    ]);

    app(MaybeRefreshEbay::class)($item);

    Queue::assertNothingPushed();
});

test('a hot card refreshed a day ago is due again', function () {
    $item = CatalogItem::factory()->create([
        'popularity' => 100,                     // hot tier => 8-hour TTL
        'ebay_refreshed_at' => now()->subDay(),
    ]);

    app(MaybeRefreshEbay::class)($item);

    Queue::assertPushed(RefreshEbaySoldComps::class);
});

test('it does nothing when eBay refresh is disabled', function () {
    config(['valuation.ebay.enabled' => false]);
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null]);

    app(MaybeRefreshEbay::class)($item);

    Queue::assertNothingPushed();
});

test('viewing a card increments popularity and queues a refresh when due', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null, 'popularity' => 0]);

    $this->get("/catalog/{$item->id}")->assertOk();

    expect($item->fresh()->popularity)->toBe(1);
    Queue::assertPushed(RefreshEbaySoldComps::class);
});
