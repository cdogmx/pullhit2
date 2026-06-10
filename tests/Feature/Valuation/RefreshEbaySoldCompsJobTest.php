<?php

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Jobs\RefreshEbaySoldComps;
use App\Models\CatalogItem;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['valuation.ebay.enabled' => true, 'valuation.ebay.view_refresh_hours' => 12]));

test('the job skips (no Oxylabs call) when the card was refreshed within the window', function () {
    Http::fake();
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(2)]);

    (new RefreshEbaySoldComps($item->id))->handle(app(IngestEbaySoldComps::class));

    Http::assertNothingSent();
});

test('the job fetches when the card is stale beyond the window', function () {
    Http::fake([
        'realtime.oxylabs.io/*' => Http::response(['results' => [['content' => '<ul></ul>']]], 200),
    ]);
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(13)]);

    (new RefreshEbaySoldComps($item->id))->handle(app(IngestEbaySoldComps::class));

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oxylabs.io'));
    expect($item->fresh()->ebay_refreshed_at->isToday())->toBeTrue();
});
