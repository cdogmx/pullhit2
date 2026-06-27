<?php

use App\Actions\Valuation\PriceHistory;
use App\Models\CatalogItem;
use App\Models\SaleObservation;

function obs(CatalogItem $item, int $price, string $daysAgo, bool $synthetic = false): void
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
    obs($item, 1000, '-21 days');
    obs($item, 1200, '-21 days');
    obs($item, 1500, '-2 days');

    $r = app(PriceHistory::class)($item);

    expect($r['estimated'])->toBeFalse()
        ->and($r['points'])->toHaveCount(2)
        ->and($r['points'][0]['price'])->toBe(1100)  // median of 1000,1200
        ->and($r['points'][0]['n'])->toBe(2)
        ->and($r['points'][1]['price'])->toBe(1500);
});

test('it falls back to the synthetic estimate when real comps are too few', function () {
    $item = CatalogItem::factory()->create();
    obs($item, 900, '-10 days');                 // 1 real
    obs($item, 1000, '-12 days', synthetic: true);
    obs($item, 1100, '-6 days', synthetic: true);

    $r = app(PriceHistory::class)($item);

    expect($r['estimated'])->toBeTrue()
        ->and(collect($r['points'])->sum('n'))->toBeGreaterThanOrEqual(3); // includes synthetic
});

test('a card with under two observations has no series', function () {
    $item = CatalogItem::factory()->create();
    obs($item, 1000, '-3 days');

    expect(app(PriceHistory::class)($item)['points'])->toBe([]);
});
