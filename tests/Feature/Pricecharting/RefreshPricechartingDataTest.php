<?php

use App\Actions\Valuation\IngestPricechartingComps;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;
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

    (new RefreshPricechartingData($box->id))->handle($ingest);
});

test('the job spends its own cap (not the eBay counter) on a real fetch', function () {
    $box = CatalogItem::factory()->sealed()->create(['pc_synced_at' => null]);

    $ingest = Mockery::mock(IngestPricechartingComps::class);
    $ingest->shouldReceive('__invoke')->once()->andReturn(5);

    (new RefreshPricechartingData($box->id))->handle($ingest);

    // The PriceCharting counter advanced; the eBay budget is untouched.
    expect((int) Cache::get(pcDailyKey(), 0))->toBe(1)
        ->and((int) Cache::get('ebay:daily:'.now()->toDateString(), 0))->toBe(0);
});
