<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;

beforeEach(fn () => config(['valuation.ebay.enabled' => true, 'valuation.ebay.view_refresh_hours' => 12]));

test('the values endpoint returns market values and a refreshing flag when stale', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null]);
    MarketValue::factory()->for($item)->create(['state_key' => 'NM']);

    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('refreshing', true)
        ->assertJsonCount(1, 'market_values');
});

test('a card refreshed within 12 hours is not refreshing', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(2)]);

    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('refreshing', false);
});
