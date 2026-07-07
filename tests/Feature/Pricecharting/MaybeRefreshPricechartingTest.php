<?php

use App\Actions\Valuation\MaybeRefreshPricecharting;
use App\Enums\ItemType;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('a never-synced sealed product dispatches a pull on view', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => null]);

    app(MaybeRefreshPricecharting::class)($box);

    Queue::assertPushed(RefreshPricechartingData::class, fn ($j) => $j->catalogItemId === $box->id);
});

test('a recently-synced sealed product does not re-pull', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => now()->subDay()]);

    app(MaybeRefreshPricecharting::class)($box);

    Queue::assertNothingPushed();
});

test('singles never pull PriceCharting (they have no sealed page)', function () {
    $single = CatalogItem::factory()->create(['item_type' => ItemType::Single, 'pc_synced_at' => null]);

    app(MaybeRefreshPricecharting::class)($single);

    Queue::assertNothingPushed();
});

test('a sealed product past the refresh window pulls again', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => now()->subDays(40)]);

    app(MaybeRefreshPricecharting::class)($box);

    Queue::assertPushed(RefreshPricechartingData::class);
});
