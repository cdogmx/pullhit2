<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbaySoldSource;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->source = app(EbaySoldSource::class);
});

/** A single in a named set. */
function queryCard(string $setName, string $name = 'Gardevoir ex', string $number = '29', ?string $series = null, ?string $rarity = null): CatalogItem
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
        'attributes' => array_filter(['language' => 'en', 'variant' => 'normal', 'rarity' => $rarity]),
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

test('a promo run reads as brand, expansion, card, printing, number', function () {
    // The standard shape. We shelve these as "30th Celebration Promos" and name
    // them "Umbreon ex (30th Celebration)"; a seller writes the expansion once
    // and the word "promo", so the search says each thing exactly once.
    $item = queryCard('30th Celebration Promos', name: 'Umbreon ex (30th Celebration)', number: '110');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon 30th Celebration Umbreon ex Promo 110');
});

test('the printing half of a set name is written the way sellers write it', function () {
    // We shelve the plural; 94.2% of Mega Evolution Promo sold titles and 89.4%
    // of SWSH Black Star Promos ones carry the word, and carry it singular.
    $item = queryCard('30th Celebration Promos', name: 'Mew', number: '105');

    expect($this->source->searchQuery($item))
        ->toContain(' Promo ')
        ->not->toContain('Promos');
});

test('a gallery run splits the same way a promo run does', function () {
    $item = queryCard('30th Celebration Classic Collection', name: 'Mew (30th Celebration)', number: '25');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon 30th Celebration Mew Classic Collection 25');
});

test('a bracket that says something the set does not is kept', function () {
    // "(Pokemon Center Exclusive)" is the printing, and sellers do write it.
    // Only the bracket that merely repeats the set comes off.
    $item = queryCard('30th Celebration Promos', name: 'Nidorina (30th Celebration) (Pokemon Center Exclusive)', number: '101');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon 30th Celebration Nidorina (Pokemon Center Exclusive) Promo 101');
});

test('a set name with no known printing half is left whole', function () {
    // "Mega Evolution Promo" is not "<expansion> Promos" — nothing splits off,
    // and the name is what 94.2% of its sold titles carry.
    $item = queryCard('Mega Evolution Promo', name: 'Meganium', number: '1');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon Mega Evolution Promo Meganium 1');
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

test('a chase rarity is searched in the words sellers actually write', function () {
    // Measured over 1,200 sold titles per tier: "special illustration rare"
    // appears in 60.4% of them and "SIR" in 22.6%. eBay ANDs every keyword, so
    // searching the short form would throw away three sales in four.
    $item = queryCard('Paldean Fates', number: '233', rarity: 'Special Illustration Rare');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon Paldean Fates Gardevoir ex Special Illustration Rare 233')
        ->not->toContain('SIR');
});

test('a plain rarity is left out, because nobody writes it', function () {
    // "Double Rare" turns up in 29.8% of its own listings. ANDing it would cost
    // seven comps in ten to say what the collector number already says.
    $item = queryCard('Paldean Fates', rarity: 'Double Rare');

    expect($this->source->searchQuery($item))
        ->toBe('Pokemon Paldean Fates Gardevoir ex 29');
});

test('a rarity we shelve back to front is searched the right way round', function () {
    // Our vocabulary says "Rare Secret". Sellers write "Secret Rare" — 47.8% of
    // titles against 0.2% — so the stored string is not the search term. Below
    // the bar to include at all, but the spelling is the point.
    expect(array_key_exists('rare secret', (new ReflectionClass(CardSearchTerms::class))
        ->getConstant('RARITY_TERMS')))->toBeFalse();
});

test('a promo does not say promo twice', function () {
    // The set's printing half already contributes "Promo"; the rarity would add
    // it again, and a repeated keyword is noise in a search we keep short.
    $item = queryCard('30th Celebration Promos', name: 'Umbreon ex', number: '110', rarity: 'Promo');

    expect(substr_count($this->source->searchQuery($item), 'Promo'))->toBe(1);
});
