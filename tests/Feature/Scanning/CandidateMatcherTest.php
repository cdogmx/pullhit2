<?php

use App\Models\CatalogItem;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\IdentifiedCard;

function card(array $attrs = []): CatalogItem
{
    return CatalogItem::factory()->create(array_merge([
        'name' => 'Pikachu ex',
        'number' => '276/217',
        'attributes' => ['language' => 'en', 'rarity' => 'SIR', 'variant' => 'holofoil'],
    ], $attrs));
}

test('it resolves a card by number + language + name', function () {
    $en = card();

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Pikachu ex', number: '276/217', setName: null, language: 'en', confidence: 0.9,
    ));

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['item']->id)->toBe($en->id)
        ->and($matches[0]['score'])->toBeGreaterThan(0.5)
        ->and($matches[0]['reasons'])->toContain('number');
});

test('language is a hard filter', function () {
    card(['attributes' => ['language' => 'en', 'rarity' => 'SIR', 'variant' => 'holofoil']]);
    $ja = card(['attributes' => ['language' => 'ja', 'rarity' => 'SIR', 'variant' => 'holofoil']]);

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Pikachu ex', number: '276/217', setName: null, language: 'ja', confidence: 0.9,
    ));

    expect($matches)->toHaveCount(1)
        ->and($matches[0]['item']->id)->toBe($ja->id);
});

test('a card not in the catalog yields no candidates', function () {
    card();

    $matches = app(CandidateMatcher::class)->match(new IdentifiedCard(
        name: 'Snorlax', number: '199/165', setName: null, language: 'en', confidence: 0.9,
    ));

    expect($matches)->toBe([]);
});
