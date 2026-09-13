<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\EbaySoldSource;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->source = app(EbaySoldSource::class);
});

/** A single in a named set. */
function queryCard(string $setName, string $name = 'Gardevoir ex', string $number = '29', ?string $series = null): CatalogItem
{
    $set = Set::factory()->create([
        'product_line_id' => test()->line->id,
        'name' => $setName,
        'series' => $series,
    ]);

    return CatalogItem::factory()->create([
        'product_line_id' => test()->line->id,
        'set_id' => $set->id,
        'item_type' => ItemType::Single,
        'name' => $name,
        'number' => $number,
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
}

test('the search reads brand, set, card, number', function () {
    // Between 88% and 95% of real sold titles name the set, and the number
    // alone does not disambiguate a printing — the Special Illustration Rare
    // Gardevoir sold as a comp for the Double Rare because its title carried
    // no number at all.
    expect($this->source->searchQuery(queryCard('Paldean Fates')))
        ->toBe('Pokemon Paldean Fates Gardevoir ex 29');
});

test('the accent is stripped from the game but not the set', function () {
    expect($this->source->searchQuery(queryCard('Paldean Fates')))
        ->toStartWith('Pokemon ')
        ->not->toContain('Pokémon');
});

test('a set name that identifies nothing is left out', function () {
    // Every product line has a "Promo" set, and "Series 2" means nothing
    // without the line it belongs to.
    foreach (['Promo', 'Promos', 'Base', 'Other', 'Series 1', 'Series 12'] as $generic) {
        expect($this->source->searchQuery(queryCard($generic)))
            ->toBe('Pokemon Gardevoir ex 29', "set name: {$generic}");
    }
});

test('a generic set name falls through to the series it sits in', function () {
    // The First Partner sets are named "Series 1/2/3", which identifies nothing,
    // while "First Partner" appears in 76% of their sold titles. Searching
    // without a set at all returned no listings for these cards.
    $card = queryCard('Series 3', name: 'Torchic', number: '56', series: 'First Partners');

    expect($this->source->searchQuery($card))
        ->toBe('Pokemon First Partners Torchic 56');
});

test('a series as generic as the name is still left out', function () {
    $card = queryCard('Promo', name: 'Torchic', number: '56', series: 'Other');

    expect($this->source->searchQuery($card))->toBe('Pokemon Torchic 56');
});

test('a long set name is left out, because eBay ANDs the keywords', function () {
    // Every word would have to appear in a listing title for it to match.
    $long = 'Starter Deck 3: The Seven Warlords of the Sea';

    expect($this->source->searchQuery(queryCard($long)))
        ->toBe('Pokemon Gardevoir ex 29');

    // Four words is still specific enough to be worth having.
    expect($this->source->searchQuery(queryCard('Scarlet & Violet 151')))
        ->toBe('Pokemon Scarlet & Violet 151 Gardevoir ex 29');
});

test('a set the card already names is not repeated', function () {
    $item = queryCard('Paldean Fates', name: 'Paldean Fates Booster');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon Paldean Fates Booster 29');
});

test('a card with no set still searches', function () {
    $item = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => null,
        'item_type' => ItemType::Single,
        'name' => 'Gardevoir ex',
        'number' => '29',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);

    expect($this->source->searchQuery($item))->toBe('Pokemon Gardevoir ex 29');
});

test('the variant qualifier stays with the card it qualifies', function () {
    $set = Set::factory()->create(['product_line_id' => $this->line->id, 'name' => 'Paldean Fates']);

    $item = CatalogItem::factory()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $set->id,
        'item_type' => ItemType::Single,
        'name' => 'Snivy',
        'number' => '1',
        'attributes' => ['language' => 'en', 'variant' => 'reverse_holo'],
    ]);

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon Paldean Fates Snivy Reverse Holo 1');
});

test('the built URL still asks for sold listings only', function () {
    $url = $this->source->soldSearchUrl(queryCard('Paldean Fates'));

    expect($url)->toContain('LH_Sold=1')
        ->toContain('LH_Complete=1')
        ->toContain(urlencode('Pokemon Paldean Fates Gardevoir ex 29'));
});
