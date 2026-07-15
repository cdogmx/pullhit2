<?php

use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
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

test('the values endpoint returns the latest real sale per state so the page can live-update it', function () {
    $item = CatalogItem::factory()->create();
    SaleObservation::factory()->for($item)->create([
        'condition' => Condition::NearMint, 'grading_company_id' => null,
        'is_synthetic' => false, 'is_outlier' => false,
        'price' => 4200, 'venue' => 'ebay', 'observed_at' => now()->subDay(),
    ]);

    // A graded sale keys under the same state as market_values ("psa-10").
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    SaleObservation::factory()->for($item)->create([
        'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10,
        'is_synthetic' => false, 'is_outlier' => false,
        'price' => 30000, 'venue' => 'ebay', 'observed_at' => now()->subDays(2),
    ]);

    // Keyed by priced state so "last sold" tracks the chart's state dropdown.
    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('last_sales.NM.price', 4200)
        ->assertJsonPath('last_sales.NM.venue', 'ebay')
        ->assertJsonPath('last_sales.psa-10.price', 30000);
});

test('a card refreshed within 12 hours is not refreshing', function () {
    $item = CatalogItem::factory()->create(['ebay_refreshed_at' => now()->subHours(2)]);

    $this->getJson("/api/v1/catalog/{$item->id}/values")
        ->assertOk()
        ->assertJsonPath('refreshing', false);
});
