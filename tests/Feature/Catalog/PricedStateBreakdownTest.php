<?php

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Enums\Condition;
use App\Enums\Venue;
use App\Models\CatalogItem;
use App\Models\SaleObservation;

beforeEach(function () {
    $this->item = CatalogItem::factory()->create();

    foreach ([1000, 1010, 990, 1005, 995] as $price) {
        SaleObservation::factory()->for($this->item)->create([
            'price' => $price,
            'condition' => Condition::NearMint,
            'venue' => Venue::Ebay,
            'observed_at' => now()->subDays(4),
            'raw' => ['title' => "Charizard sold at {$price}", 'url' => "https://www.ebay.com/itm/{$price}"],
        ]);
    }
    $this->outlier = SaleObservation::factory()->for($this->item)->create([
        'price' => 9000,
        'condition' => Condition::NearMint,
        'venue' => Venue::Ebay,
        'observed_at' => now()->subDays(4),
        'raw' => ['title' => 'Charizard wild ask', 'url' => 'https://www.ebay.com/itm/9000'],
    ]);

    app(RecomputeCatalogItem::class)($this->item);
});

test('the breakdown endpoint returns the value, comps, and sources', function () {
    $this->getJson("/api/v1/catalog/{$this->item->id}/observations?state_key=NM")
        ->assertOk()
        ->assertJsonPath('value.state_key', 'NM')
        ->assertJsonCount(6, 'observations')          // includes the outlier
        ->assertJsonPath('sources.ebay', 6)
        ->assertJson(fn ($json) => $json->has('observations.0.url')->etc());
});

test('the breakdown flags excluded outliers', function () {
    $data = $this->getJson("/api/v1/catalog/{$this->item->id}/observations?state_key=NM")->json();

    $flagged = collect($data['observations'])->where('is_outlier', true);
    expect($flagged)->toHaveCount(1)
        ->and($flagged->first()['price'])->toBe(9000);
});

test('an unknown priced state returns 404', function () {
    $this->getJson("/api/v1/catalog/{$this->item->id}/observations?state_key=psa-10")
        ->assertNotFound()
        ->assertJsonPath('value', null);
});
