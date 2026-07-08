<?php

use App\Actions\Valuation\IngestPricechartingComps;
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

test('an already-synced sealed product never re-pulls on view (once-ever)', function () {
    // Synced recently OR long ago — either way it is never auto-re-pulled.
    foreach ([now()->subDay(), now()->subDays(90)] as $syncedAt) {
        Queue::fake();
        $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => $syncedAt]);

        app(MaybeRefreshPricecharting::class)($box);

        Queue::assertNothingPushed();
    }
});

test('a never-synced single also dispatches a pull on view (per-grade history)', function () {
    $single = CatalogItem::factory()->create(['item_type' => ItemType::Single, 'pc_synced_at' => null]);

    app(MaybeRefreshPricecharting::class)($single);

    Queue::assertPushed(RefreshPricechartingData::class, fn ($j) => $j->catalogItemId === $single->id);
});

test('the job itself skips an already-synced item unless forced', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => now()->subDay()]);
    $ingest = Mockery::mock(IngestPricechartingComps::class);
    $ingest->shouldNotReceive('__invoke');

    (new RefreshPricechartingData($box->id))->handle($ingest);
});
