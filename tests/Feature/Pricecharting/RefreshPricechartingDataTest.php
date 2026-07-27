<?php

use App\Actions\Valuation\IngestPricechartingComps;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Support\Facades\Cache;

function pcDailyKey(): string
{
    return 'pricecharting:daily:'.now()->toDateString();
}

test('the job skips the fetch when PriceCharting\'s own daily cap is reached', function () {
    Cache::put(pcDailyKey(), (int) config('valuation.pricecharting.daily_cap'), now()->addHour());
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => null]);

    $ingest = Mockery::mock(IngestPricechartingComps::class);
    $ingest->shouldNotReceive('__invoke');

    (new RefreshPricechartingData($box->id))->handle($ingest, app(OxylabsClient::class));
});

test('the job proceeds to the ingest while its own budget has room', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => null]);

    $ingest = Mockery::mock(IngestPricechartingComps::class);
    $ingest->shouldReceive('__invoke')->once()->andReturn(5);

    (new RefreshPricechartingData($box->id))->handle($ingest, app(OxylabsClient::class));
})->throwsNoExceptions();

// Spending itself is OxylabsClient's job now — it's the only layer that knows how
// many requests a fetch actually billed. See OxylabsBudgetTest.
