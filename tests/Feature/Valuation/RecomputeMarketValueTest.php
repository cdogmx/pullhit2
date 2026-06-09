<?php

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Enums\Condition;
use App\Enums\Venue;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;

beforeEach(function () {
    $this->item = CatalogItem::factory()->create();

    foreach ([1000, 1010, 990, 1005, 995] as $price) {
        SaleObservation::factory()->for($this->item)->create([
            'price' => $price,
            'condition' => Condition::NearMint,
            'venue' => Venue::TCGplayer,
            'observed_at' => now()->subDays(5),
        ]);
    }

    $this->outlier = SaleObservation::factory()->for($this->item)->create([
        'price' => 9000,
        'condition' => Condition::NearMint,
        'venue' => Venue::TCGplayer,
        'observed_at' => now()->subDays(5),
    ]);
});

test('recompute writes one market_values row per priced state with a sane distribution', function () {
    app(RecomputeCatalogItem::class)($this->item);

    $value = MarketValue::where('catalog_item_id', $this->item->id)->where('state_key', 'NM')->first();

    expect($value)->not->toBeNull()
        ->and($value->n_sales)->toBe(5)                 // outlier excluded
        ->and($value->median)->toBeGreaterThan(900)->toBeLessThan(1100)
        ->and($value->low)->toBeLessThanOrEqual($value->median)
        ->and($value->high)->toBeGreaterThanOrEqual($value->median)
        ->and($value->confidence)->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);
});

test('recompute flags the outlier observation (never deletes it)', function () {
    app(RecomputeCatalogItem::class)($this->item);

    expect($this->outlier->fresh()->is_outlier)->toBeTrue();
    expect(SaleObservation::count())->toBe(6); // nothing deleted
});

test('recompute is idempotent', function () {
    app(RecomputeCatalogItem::class)($this->item);
    app(RecomputeCatalogItem::class)($this->item);

    expect(MarketValue::where('catalog_item_id', $this->item->id)->count())->toBe(1);
});

test('an item with no observations gets no market value', function () {
    $empty = CatalogItem::factory()->create();
    app(RecomputeCatalogItem::class)($empty);

    expect(MarketValue::where('catalog_item_id', $empty->id)->exists())->toBeFalse();
});
