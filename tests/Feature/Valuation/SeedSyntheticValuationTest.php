<?php

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;

test('it seeds an estimated NM value from a price anchor', function () {
    $item = CatalogItem::factory()->create([
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'normal'],
    ]);

    app(SeedSyntheticValuation::class)($item, 1000);

    $nm = MarketValue::where('catalog_item_id', $item->id)->where('state_key', 'NM')->first();
    expect($nm)->not->toBeNull()
        ->and($nm->is_estimated)->toBeTrue()
        ->and($nm->median)->toBeGreaterThan(0);
});

test('a chase holo anchor adds graded states', function () {
    GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $item = CatalogItem::factory()->create([
        'attributes' => ['language' => 'en', 'rarity' => 'Special Illustration Rare', 'variant' => 'holo'],
    ]);

    app(SeedSyntheticValuation::class)($item, 30000); // $300 chase

    $keys = MarketValue::where('catalog_item_id', $item->id)->pluck('state_key');
    expect($keys)->toContain('NM')->toContain('psa-10');
});

test('it preserves real comps and replaces only synthetic ones', function () {
    $item = CatalogItem::factory()->create([
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'normal'],
    ]);
    $item->saleObservations()->create([
        'condition' => 'NM', 'venue' => 'ebay', 'price' => 1234, 'currency' => 'USD',
        'observed_at' => now()->subDay(), 'is_outlier' => false, 'is_synthetic' => false,
    ]);

    app(SeedSyntheticValuation::class)($item, 1000);
    app(SeedSyntheticValuation::class)($item, 1000); // re-run

    // The one real comp survives both runs.
    expect($item->saleObservations()->where('is_synthetic', false)->count())->toBe(1);
});
