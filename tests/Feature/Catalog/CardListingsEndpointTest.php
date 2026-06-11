<?php

use App\Models\CatalogItem;
use Illuminate\Support\Facades\Http;

test('the listings endpoint returns listings + affiliate urls and caches the fetch', function () {
    config([
        'services.ebay.client_id' => 'id',
        'services.ebay.client_secret' => 'secret',
        'services.ebay.campaign_id' => '5338',
        'services.ebay.base_url' => 'https://api.ebay.com',
    ]);
    Http::fake([
        'api.ebay.com/identity/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 7200]),
        'api.ebay.com/buy/browse/v1/item_summary/search*' => Http::response(['itemSummaries' => [
            ['title' => 'X', 'price' => ['value' => '1.00', 'currency' => 'USD'], 'itemWebUrl' => 'https://ebay.com/itm/1'],
        ]]),
    ]);
    $item = CatalogItem::factory()->create();

    $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        ->assertJsonCount(1, 'listings')
        ->assertJsonPath('configured', true)
        ->assertJsonPath('ebay_options.0.label', 'Near Mint')
        ->assertJsonPath('ebay_options.0.url', fn ($u) => str_contains($u, 'campid=5338') && str_contains($u, 'Near+Mint'));

    // Second call is served from cache — only the original token + search went out.
    $this->getJson("/api/v1/catalog/{$item->id}/listings")->assertOk();
    Http::assertSentCount(2);
});

test('the listings endpoint works (affiliate links only) when Browse is unconfigured', function () {
    config(['services.ebay.client_id' => null, 'services.ebay.client_secret' => null, 'services.ebay.campaign_id' => '5338']);
    Http::fake();
    $item = CatalogItem::factory()->create();

    $this->getJson("/api/v1/catalog/{$item->id}/listings")
        ->assertOk()
        ->assertJsonPath('configured', false)
        ->assertJsonCount(0, 'listings')
        ->assertJsonPath('ebay_options.0.url', fn ($u) => str_contains($u, 'campid=5338'));
});
