<?php

use App\Actions\Catalog\GetCardListings;
use App\Actions\Valuation\IngestForSaleListings;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\CardSearchTerms;

beforeEach(function () {
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon', 'name' => 'Pokémon']);

    $this->card = function (string $set, string $name, string $number, array $attributes = []) {
        return CatalogItem::factory()->create([
            'product_line_id' => $this->line->id,
            'set_id' => Set::factory()->for($this->line)->create(['name' => $set, 'language' => 'en'])->id,
            'name' => $name,
            'number' => $number,
            'attributes' => array_merge(['language' => 'en', 'variant' => 'holo'], $attributes),
        ]);
    };
});

/** Both Browse callers must ask eBay the same thing. */
function browseQueries(CatalogItem $item): array
{
    $panel = new ReflectionMethod(GetCardListings::class, 'singleQuery');
    $panel->setAccessible(true);
    $ingest = new ReflectionMethod(IngestForSaleListings::class, 'baseQuery');
    $ingest->setAccessible(true);

    return [
        $panel->invoke(app(GetCardListings::class), $item),
        $ingest->invoke(app(IngestForSaleListings::class), $item),
    ];
}

test('the listings panel and the ask ingest ask the same question', function () {
    // They were two separate builders. The panel grew every rule the sold query
    // grew; the ingest kept pasting in the raw name, number and set — so a card
    // could show forty sold comps and a single ask.
    $card = ($this->card)('30th Celebration', 'Pikachu ex', '149', ['rarity' => 'Special Illustration Rare']);

    [$panel, $ingest] = browseQueries($card);

    expect($panel)->toBe($ingest)
        ->and($panel)->toBe('Pikachu ex 149 30th Celebration Special Illustration Rare');
});

test('a refiled promo no longer asks for words no listing carries', function () {
    // Before: "Umbreon ex (30th Celebration) 110 30th Celebration Promos Promo"
    // — our bracket, our shelving plural, and "Promo" twice, every word ANDed.
    $card = ($this->card)('30th Celebration Promos', 'Umbreon ex (30th Celebration)', '110', ['rarity' => 'Promo']);

    [$panel, $ingest] = browseQueries($card);

    expect($panel)->toBe($ingest)
        ->and($panel)->toBe('Umbreon ex 110 30th Celebration Promo');
});

test('a colourway asks for the two words its sellers agree on', function () {
    $card = ($this->card)('30th Celebration', 'Mew', '30C', ['finish' => 'blue_rgb']);

    [$panel, $ingest] = browseQueries($card);

    expect($panel)->toBe($ingest)->and($panel)->toBe('Mew RGB');
});

test('the shared builder is what both of them call', function () {
    $card = ($this->card)('Paldean Fates', 'Gardevoir ex', '233', ['rarity' => 'Illustration Rare']);

    [$panel, $ingest] = browseQueries($card);

    expect(CardSearchTerms::browseQuery($card))->toBe($panel)->toBe($ingest);
});
