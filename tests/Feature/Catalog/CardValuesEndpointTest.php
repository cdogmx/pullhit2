<?php

use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;

beforeEach(fn () => config(['valuation.ebay.enabled' => true, 'valuation.ebay.view_refresh_hours' => 12]));

test('the values endpoint returns market values and a refreshing flag when stale', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => null]);
    MarketValue::factory()->for($item)->create(['state_key' => 'NM']);

    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('refreshing', true)
        ->assertJsonCount(1, 'market_values');
});

test('the values endpoint returns the latest real sale so the page can live-update it', function () {
    $item = CatalogItem::factory()->create();
    SaleObservation::factory()->for($item)->create([
        'condition' => Condition::NearMint, 'grading_company_id' => null,
        'is_synthetic' => false, 'is_outlier' => false,
        'price' => 4200, 'venue' => 'ebay', 'observed_at' => now()->subDay(),
    ]);

    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('last_sale.price', 4200)
        ->assertJsonPath('last_sale.venue', 'ebay');
});

test('a card refreshed within 12 hours is not refreshing', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(2)]);

    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('refreshing', false);
});
