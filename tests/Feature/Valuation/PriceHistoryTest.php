<?php

use App\Actions\Valuation\PriceHistory;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\SaleObservation;

function phObs(CatalogItem $item, int $price, string $daysAgo, bool $synthetic = false): void
{
    SaleObservation::create([
        'catalog_item_id' => $item->id,
        'condition' => 'NM',
        'venue' => 'ebay',
        'price' => $price,
        'currency' => 'USD',
        'observed_at' => now()->parse($daysAgo),
        'is_outlier' => false,
        'is_synthetic' => $synthetic,
        'raw' => [],
    ]);
}

test('it returns weekly-median sold points from real observations', function () {
    $item = CatalogItem::factory()->create();
    // Week A: two sales same day (median 1100); a later week: one sale (1500).
    phObs($item, 1000, '-21 days');
    phObs($item, 1200, '-21 days');
    phObs($item, 1500, '-2 days');

    $r = app(PriceHistory::class)($item);

    expect($r['estimated'])->toBeFalse()
        ->and($r['points'])->toHaveCount(2)
        ->and($r['points'][0]['price'])->toBe(1100)  // median of 1000,1200
        ->and($r['points'][0]['n'])->toBe(2)
        ->and($r['points'][1]['price'])->toBe(1500);
});

test('it falls back to the synthetic estimate when real comps are too few', function () {
    $item = CatalogItem::factory()->create();
    phObs($item, 900, '-10 days');                 // 1 real
    phObs($item, 1000, '-12 days', synthetic: true);
    phObs($item, 1100, '-6 days', synthetic: true);

    $r = app(PriceHistory::class)($item);

    expect($r['estimated'])->toBeTrue()
        ->and(collect($r['points'])->sum('n'))->toBeGreaterThanOrEqual(3); // includes synthetic
});

test('a card with under two observations has no series', function () {
    $item = CatalogItem::factory()->create();
    phObs($item, 1000, '-3 days');

    expect(app(PriceHistory::class)($item)['points'])->toBe([]);
});

test('a state key filters the series to that graded slab', function () {
    $item = CatalogItem::factory()->create();
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    MarketValue::factory()->for($item)->create([
        'state_key' => 'PSA-10', 'condition' => null,
        'grading_company_id' => $psa->id, 'grade' => 10, 'median' => 50000,
    ]);

    // PSA-10 comps across two weeks (median 50000, then 60000).
    foreach ([['-21 days', 48000], ['-21 days', 52000], ['-2 days', 60000]] as [$when, $price]) {
        SaleObservation::create([
            'catalog_item_id' => $item->id, 'condition' => null, 'venue' => 'ebay',
            'price' => $price, 'currency' => 'USD', 'observed_at' => now()->parse($when),
            'is_outlier' => false, 'is_synthetic' => false,
            'grading_company_id' => $psa->id, 'grade' => 10, 'raw' => [],
        ]);
    }

    // A raw comp that must NOT leak into the graded series.
    phObs($item, 1000, '-3 days');

    $r = app(PriceHistory::class)($item, 365, 'PSA-10');

    expect($r['estimated'])->toBeFalse()
        ->and($r['points'])->toHaveCount(2)
        ->and($r['points'][0]['price'])->toBe(50000)
        ->and($r['points'][1]['price'])->toBe(60000);
});

test('the price-history endpoint returns the series for a state', function () {
    $item = CatalogItem::factory()->create();
    phObs($item, 1000, '-21 days');
    phObs($item, 1200, '-21 days');
    phObs($item, 1500, '-2 days');

    $this->getJson("/api/v1/catalog/{$item->id}/price-history")
        ->assertOk()
        ->assertJsonPath('estimated', false)
        ->assertJsonCount(2, 'points');
});
