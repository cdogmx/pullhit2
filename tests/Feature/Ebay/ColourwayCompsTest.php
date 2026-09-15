<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\SoldCompClassifier;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($this->line)->create([
        'slug' => '30th-celebration', 'name' => '30th Celebration', 'language' => 'en',
    ]);
    $this->classifier = new SoldCompClassifier;
    $this->companies = ['psa' => GradingCompany::factory()->create(['slug' => 'psa'])->id];

    $this->mew = fn (string $colour) => CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $this->set->id,
        'name' => 'Mew',
        'number' => '30C',
        'attributes' => ['language' => 'en', 'variant' => 'holo', 'finish' => $colour.'_rgb'],
    ]);
});

/** The three spellings sellers actually used, one per real listing. */
dataset('blue titles', [
    'face code' => 'Pokemon 30TH CELEBRATIONS MEW B/RGB SECRET RARE 1/20k PACK HIT',
    'colour word' => 'Pokemon Mew Ultra Rare Blue 30th Anniversary Celebration',
    'both, plus number' => 'Mew 30C 30th Celebration Blue RGB Near Mint',
]);

test('the blue print takes every way a seller writes blue', function (string $title) {
    expect($this->classifier->classify(
        candidate($title, 2_000_000), ($this->mew)('blue'), 1_500_000, $this->companies,
    ))->not->toBeNull();
})->with('blue titles');

test('the red and green prints take none of them', function (string $title) {
    // One of these sold for $100,000 and the others did not. Pooling three
    // colourways would be the most expensive kind of wrong.
    foreach (['red', 'green'] as $colour) {
        expect($this->classifier->classify(
            candidate($title, 2_000_000), ($this->mew)($colour), 1_500_000, $this->companies,
        ))->toBeNull("{$colour}: {$title}");
    }
})->with('blue titles');

test('pull odds are not a collector number', function () {
    // "1/20k PACK HIT" parsed as collector number 1, contradicted 30C, and threw
    // away the $100,000 sale. No set has twenty thousand cards in it.
    expect($this->classifier->classify(
        candidate('Pokemon 30TH CELEBRATIONS MEW B/RGB SECRET RARE 1/20k PACK HIT', 2_000_000),
        ($this->mew)('blue'), 1_500_000, $this->companies,
    ))->not->toBeNull();
});

test('a real collector number still contradicts', function () {
    // The odds rule must not blunt the gate it lives in.
    expect($this->classifier->classify(
        candidate('Pokemon 30th Celebration Mew Blue 99/159', 2_000_000),
        ($this->mew)('blue'), 1_500_000, $this->companies,
    ))->toBeNull();
});

test('the colour is gated, not searched', function () {
    // No colour token is common to the listings — one says "B/RGB", another is
    // found by "blue" — and eBay ANDs, so either spelling in the query loses the
    // other seller's sale. The number goes too: two of the three titles omit it.
    expect(app(EbaySoldSource::class)->searchQuery(($this->mew)('blue')))
        ->toBe('Pokemon 30th Celebration Mew');
});

test('a card that is not a colourway keeps its number', function () {
    $ordinary = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $this->set->id,
        'name' => 'Pikachu', 'number' => '25',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    expect(app(EbaySoldSource::class)->searchQuery($ordinary))
        ->toBe('Pokemon 30th Celebration Pikachu 25');
});
