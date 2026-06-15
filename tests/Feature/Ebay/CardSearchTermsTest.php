<?php

use App\Models\CatalogItem;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\OxylabsClient;

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

test('the sold-comp eBay query includes the printing terms', function () {
    $item = CatalogItem::factory()->make([
        'name' => 'Charizard', 'number' => '4',
        'attributes' => ['variant' => 'holo', 'edition' => 'first_edition'],
    ]);
    $item->setRelation('set', App\Models\Set::factory()->make(['name' => 'Base', 'code' => 'BS']));

    $query = (new EbaySoldSource(app(OxylabsClient::class)))->searchQuery($item);

    expect($query)->toContain('Charizard')->toContain('4')->toContain('Base (BS)')->toContain('1st Edition');
});
