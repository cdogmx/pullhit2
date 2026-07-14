<?php

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\OxylabsClient;

function sealedQuery(string $name, string $line, string $setName, ?string $code = 'BLK'): string
{
    $item = CatalogItem::factory()->make(['name' => $name, 'item_type' => ItemType::Sealed]);
    $item->setRelation('productLine', ProductLine::factory()->make(['name' => $line]));
    $item->setRelation('set', Set::factory()->make(['name' => $setName, 'code' => $code]));

    return (new EbaySoldSource(app(OxylabsClient::class)))->searchQuery($item);
}

test('qualifiers pin the search to a printing', function () {
    $make = fn (array $a) => CatalogItem::factory()->make(['attributes' => $a]);

    expect(CardSearchTerms::qualifiers($make(['variant' => 'holo', 'edition' => 'first_edition'])))->toBe(['1st Edition']);
    expect(CardSearchTerms::qualifiers($make(['variant' => 'holo', 'edition' => 'shadowless'])))->toBe(['Shadowless']);
    expect(CardSearchTerms::qualifiers($make(['variant' => 'reverse_holo'])))->toBe(['Reverse Holo']);
    expect(CardSearchTerms::qualifiers($make(['variant' => 'holo', 'edition' => 'unlimited', 'finish' => 'black_dot_error'])))->toBe(['Black Dot Error']);

    // Unlimited adds no positive term (the classifier excludes other editions).
    expect(CardSearchTerms::qualifiers($make(['variant' => 'holo', 'edition' => 'unlimited'])))->toBe([]);
    expect(CardSearchTerms::qualifiers($make(['variant' => 'holo'])))->toBe([]);
});

test('a single query is game - name - variant - number (no set/code)', function () {
    $item = CatalogItem::factory()->make([
        'name' => 'Snivy', 'number' => '1',
        'attributes' => ['language' => 'en', 'variant' => 'reverse_holo'],
    ]);
    $item->setRelation('productLine', ProductLine::factory()->make(['name' => 'Pokémon']));
    $item->setRelation('set', Set::factory()->make(['name' => 'Black Bolt', 'code' => 'BLK']));

    $query = (new EbaySoldSource(app(OxylabsClient::class)))->searchQuery($item);

    expect($query)->toBe('Pokemon - Snivy - Reverse Holo - 1');
});

test('the sold-comp eBay query still carries every printing qualifier', function () {
    $item = CatalogItem::factory()->make([
        'name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'variant' => 'holo', 'edition' => 'first_edition'],
    ]);
    $item->setRelation('productLine', ProductLine::factory()->make(['name' => 'Pokémon']));

    $query = (new EbaySoldSource(app(OxylabsClient::class)))->searchQuery($item);

    expect($query)->toBe('Pokemon - Charizard - 1st Edition - 4');
});

test('the sold URL filters to the card language via the Language aspect', function () {
    $en = CatalogItem::factory()->make(['name' => 'Snivy', 'number' => '1', 'attributes' => ['language' => 'en']]);
    $ja = CatalogItem::factory()->make(['name' => 'Snivy', 'number' => '1', 'attributes' => ['language' => 'ja']]);
    $unknown = CatalogItem::factory()->make(['name' => 'Snivy', 'number' => '1', 'attributes' => []]);
    $src = new EbaySoldSource(app(OxylabsClient::class));

    expect($src->soldSearchUrl($en))->toContain('Language=English')
        ->and($src->soldSearchUrl($ja))->toContain('Language=Japanese')
        ->and($src->soldSearchUrl($unknown))->not->toContain('Language=');
});

test('a sealed query uses natural retail wording, not the card-identity form', function () {
    // Set name already in the product name → not duplicated; no (CODE); game prefixed.
    expect(sealedQuery('Black Bolt Booster Bundle', 'Pokémon', 'Black Bolt'))
        ->toBe('Pokemon - Black Bolt Booster Bundle');
});

test('a sealed query folds in the set name when the product name lacks it', function () {
    expect(sealedQuery('Booster Pack', 'Pokémon', 'Ascended Heroes'))
        ->toBe('Pokemon - Ascended Heroes - Booster Pack');

    expect(sealedQuery('Unova Mini Tin [Chandelure & Zorua]', 'Pokémon', 'Black Bolt'))
        ->toBe('Pokemon - Black Bolt - Unova Mini Tin [Chandelure & Zorua]');
});

test('a sealed query does not repeat a game name already in the product name', function () {
    expect(sealedQuery("Disney Lorcana: Archazia's Island Starter Deck", 'Disney Lorcana', "Archazia's Island"))
        ->toBe("Disney Lorcana: Archazia's Island Starter Deck");
});
