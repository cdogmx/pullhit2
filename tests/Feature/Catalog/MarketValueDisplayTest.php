<?php

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Enums\Condition;
use App\Enums\Venue;
use App\Models\CatalogItem;
use App\Models\SaleObservation;

function itemWithValue(): CatalogItem
{
    $item = CatalogItem::factory()->create();

    foreach ([1000, 1010, 990, 1005, 995, 1020] as $price) {
        SaleObservation::factory()->for($item)->create([
            'price' => $price,
            'condition' => Condition::NearMint,
            'venue' => Venue::TCGplayer,
            'observed_at' => now()->subDays(4),
        ]);
    }
    app(RecomputeCatalogItem::class)($item);

    return $item;
}

test('the catalog API exposes the headline market value', function () {
    itemWithValue();

    $this->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.market_value.n_sales', 6)
        ->assertJsonPath('data.0.market_value.confidence_label', fn ($l) => in_array($l, ['Low', 'Medium', 'High'], true))
        ->assertJson(fn ($json) => $json->has('data.0.market_value.median')->etc());
});

test('the detail API exposes every priced state', function () {
    $item = itemWithValue();

    $this->getJson("/api/v1/catalog/{$item->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.market_values')
        ->assertJsonPath('data.market_values.0.label', 'Near Mint');
});

test('an item with no observations has a null market value', function () {
    CatalogItem::factory()->create();

    $this->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('data.0.market_value', null);
});
