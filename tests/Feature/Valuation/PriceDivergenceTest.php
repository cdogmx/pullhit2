<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\PricechartingProduct;
use App\Models\Set;

function divergenceCard(Set $set, string $name, string $number, ?int $ourCents, ?int $theirCents, bool $estimated = false): CatalogItem
{
    $item = CatalogItem::factory()->create([
        'set_id' => $set->id, 'name' => $name, 'number' => $number,
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    if ($ourCents !== null) {
        MarketValue::factory()->for($item)->create([
            'state_key' => 'NM', 'condition' => 'NM',
            'median' => $ourCents, 'is_estimated' => $estimated,
        ]);
    }

    if ($theirCents !== null) {
        PricechartingProduct::create([
            'pc_id' => 'pc-'.$name.$number, 'console_name' => 'Pokemon Test',
            'product_name' => "{$name} #{$number}", 'language' => 'en',
            'set_id' => $set->id, 'card_name' => $name, 'number' => $number,
            'is_sealed' => false, 'price_ungraded' => $theirCents,
        ]);
    }

    return $item;
}

test('it flags a card we price far above the outside reference', function () {
    $set = Set::factory()->create();
    divergenceCard($set, 'Flying Pikachu V', '6', 5335, 428);   // the live case, 12.5x
    divergenceCard($set, 'Sensible Card', '7', 1000, 1100);     // fine

    $this->artisan('valuation:price-divergence', ['--ratio' => 3, '--set' => $set->slug])
        ->expectsOutputToContain('Flying Pikachu V')
        ->doesntExpectOutputToContain('Sensible Card')
        ->assertSuccessful();
});

test('it flags the low side too', function () {
    // Today's reclassify pass moved 235 cards UP out of a wrong Heavily Played
    // filing, so undervaluation is a real failure mode, not a hypothetical. A
    // report that only looked at the high side would have missed all of them.
    $set = Set::factory()->create();
    divergenceCard($set, 'Undervalued Card', '9', 500, 8000);

    $this->artisan('valuation:price-divergence', ['--ratio' => 3, '--set' => $set->slug])
        ->expectsOutputToContain('Undervalued Card')
        ->assertSuccessful();
});

test('it ignores estimated values', function () {
    // An estimate is our model talking to itself. Comparing it against
    // PriceCharting measures the model, not the data, and would bury the real
    // divergences under noise.
    $set = Set::factory()->create();
    divergenceCard($set, 'Modelled Card', '11', 9000, 300, estimated: true);

    $this->artisan('valuation:price-divergence', ['--ratio' => 3, '--set' => $set->slug])
        ->doesntExpectOutputToContain('Modelled Card')
        ->assertSuccessful();
});

test('it ignores penny cards, where the ratio is noise', function () {
    // A card that moves between $0.30 and $1.20 is a 4x divergence and means
    // nothing. Leaving these in would fill the report with rounding.
    $set = Set::factory()->create();
    divergenceCard($set, 'Penny Card', '12', 120, 30);

    $this->artisan('valuation:price-divergence', ['--ratio' => 3, '--min-cents' => 300, '--set' => $set->slug])
        ->doesntExpectOutputToContain('Penny Card')
        ->assertSuccessful();
});

test('it says so plainly when there is nothing to compare', function () {
    $set = Set::factory()->create();
    divergenceCard($set, 'No Reference', '13', 5000, null);

    $this->artisan('valuation:price-divergence', ['--set' => $set->slug])
        ->expectsOutputToContain('Nothing to compare')
        ->assertSuccessful();
});
