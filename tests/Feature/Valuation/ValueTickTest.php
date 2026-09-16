<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\ValueTick;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration', 'language' => 'en',
    ]);

    $this->priced = function (Set $set, int $median, bool $estimated = false) {
        $item = CatalogItem::factory()->create([
            'product_line_id' => $this->line->id, 'set_id' => $set->id,
            'attributes' => ['language' => 'en'],
        ]);
        MarketValue::factory()->for($item)->create([
            'state_key' => 'NM', 'grading_company_id' => null,
            'median' => $median, 'is_estimated' => $estimated, 'n_sales' => 4, 'for_sale' => $median + 1000,
        ]);

        return $item;
    };
});

test('nothing featured means nothing written', function () {
    ($this->priced)($this->set, 5000);

    $this->artisan('valuation:tick')->assertSuccessful();

    expect(ValueTick::count())->toBe(0);
});

test('a featured set is read, and its subsets with it', function () {
    $promos = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration-promos', 'name' => '30th Celebration Promos', 'language' => 'en',
    ]);
    ($this->priced)($this->set, 5000);
    ($this->priced)($promos, 40000);

    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);
    $this->artisan('valuation:tick')->assertSuccessful();

    expect(ValueTick::count())->toBe(2)
        ->and(ValueTick::pluck('median_cents')->sort()->values()->all())->toBe([5000, 40000]);
});

test('a guessed value is not a reading', function () {
    ($this->priced)($this->set, 5000);
    ($this->priced)($this->set, 99000, estimated: true);

    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);
    $this->artisan('valuation:tick');

    expect(ValueTick::count())->toBe(1)
        ->and(ValueTick::first()->median_cents)->toBe(5000);
});

test('two firings in the same minute are one reading, not two', function () {
    // The series resolution is the scheduler's cadence; a retry or an overlapping
    // run must not double a point and bend the line.
    ($this->priced)($this->set, 5000);
    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);

    $this->artisan('valuation:tick');
    $this->artisan('valuation:tick');

    expect(ValueTick::count())->toBe(1);
});

test('the ask price rides along with the median', function () {
    // Asks are the half of this that genuinely moves hourly — eBay dates a sold
    // listing without a time, so the median only moves as sales are found.
    ($this->priced)($this->set, 5000);
    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);
    $this->artisan('valuation:tick');

    expect(ValueTick::first()->for_sale_cents)->toBe(6000);
});

test('old readings are pruned', function () {
    ($this->priced)($this->set, 5000);
    $this->artisan('catalog:feature-set', ['set' => '30th-celebration']);

    ValueTick::create([
        'catalog_item_id' => CatalogItem::first()->id, 'state_key' => 'NM',
        'median_cents' => 100, 'n_sales' => 1, 'captured_at' => now()->subDays(40),
    ]);

    $this->artisan('valuation:tick', ['--prune' => 30]);

    expect(ValueTick::where('captured_at', '<', now()->subDays(30))->count())->toBe(0)
        ->and(ValueTick::count())->toBe(1);
});
