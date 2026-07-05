<?php

use App\Actions\Catalog\CatalogFilterOptions;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;

function makeCard(ProductLine $line, Set $set, array $attributes): CatalogItem
{
    return CatalogItem::factory()->create([
        'product_line_id' => $line->id,
        'set_id' => $set->id,
        'attributes' => array_merge(['language' => 'en'], $attributes),
    ]);
}

test('value facets narrow to the selected product line', function () {
    $poke = ProductLine::factory()->create(['slug' => 'pokemon']);
    $cyber = ProductLine::factory()->create(['slug' => 'cyberpunk']);
    $pokeSet = Set::factory()->create(['product_line_id' => $poke->id, 'slug' => 'base']);
    $cyberSet = Set::factory()->create(['product_line_id' => $cyber->id, 'slug' => 'wnc']);

    makeCard($poke, $pokeSet, ['rarity' => 'Rare Holo', 'variant' => 'holo']);
    makeCard($cyber, $cyberSet, ['rarity' => 'Legend', 'variant' => 'normal']);

    $opts = app(CatalogFilterOptions::class)(['product_line' => 'cyberpunk']);

    // Only cyberpunk's rarity shows; Pokémon's "Rare Holo" is excluded.
    expect($opts['rarities'])->toBe(['Legend']);
});

test('a scope with only normal printings shows no variant filter', function () {
    $cyber = ProductLine::factory()->create(['slug' => 'cyberpunk']);
    $set = Set::factory()->create(['product_line_id' => $cyber->id, 'slug' => 'wnc']);
    makeCard($cyber, $set, ['variant' => 'normal']);
    makeCard($cyber, $set, ['variant' => 'normal']);

    $opts = app(CatalogFilterOptions::class)(['product_line' => 'cyberpunk']);

    expect($opts['variants'])->toBe([]);
});

test('variants are returned (registry-ordered) when more than just normal exist', function () {
    $poke = ProductLine::factory()->create(['slug' => 'pokemon']);
    $set = Set::factory()->create(['product_line_id' => $poke->id, 'slug' => 'base']);
    makeCard($poke, $set, ['variant' => 'reverse_holo']);
    makeCard($poke, $set, ['variant' => 'normal']);

    $opts = app(CatalogFilterOptions::class)(['product_line' => 'pokemon']);

    // Registry order is normal, holo, reverse_holo.
    expect($opts['variants'])->toBe(['normal', 'reverse_holo']);
});

test('grading facets list only graders (and grades) present in scope', function () {
    $poke = ProductLine::factory()->create(['slug' => 'pokemon']);
    $set = Set::factory()->create(['product_line_id' => $poke->id, 'slug' => 'base']);
    $card = makeCard($poke, $set, ['rarity' => 'Rare Holo']);

    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $bgs = GradingCompany::factory()->create(['slug' => 'bgs', 'name' => 'BGS']);

    // A raw value and two PSA grades — but no BGS value in scope.
    MarketValue::factory()->for($card)->create(['state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'grade' => null]);
    MarketValue::factory()->for($card)->create(['state_key' => 'PSA-10', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10]);
    MarketValue::factory()->for($card)->create(['state_key' => 'PSA-9', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 9]);

    $opts = app(CatalogFilterOptions::class)(['product_line' => 'pokemon']);

    expect(collect($opts['grading_companies'])->pluck('slug')->all())->toBe(['psa'])
        ->and($opts['grades'])->toBe([10.0, 9.0]); // highest first
    expect($bgs)->not->toBeNull(); // BGS exists but has no value in scope, so it's excluded
});

test('grades narrow to the selected grader', function () {
    $poke = ProductLine::factory()->create(['slug' => 'pokemon']);
    $set = Set::factory()->create(['product_line_id' => $poke->id, 'slug' => 'base']);
    $card = makeCard($poke, $set, ['rarity' => 'Rare Holo']);

    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $bgs = GradingCompany::factory()->create(['slug' => 'bgs', 'name' => 'BGS']);

    MarketValue::factory()->for($card)->create(['state_key' => 'PSA-10', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10]);
    MarketValue::factory()->for($card)->create(['state_key' => 'BGS-9.5', 'condition' => null, 'grading_company_id' => $bgs->id, 'grade' => 9.5]);

    $opts = app(CatalogFilterOptions::class)(['product_line' => 'pokemon', 'grading_company' => 'bgs']);

    expect($opts['grades'])->toBe([9.5]);
});

test('languages narrow to the selected product line', function () {
    $poke = ProductLine::factory()->create(['slug' => 'pokemon']);
    $jp = ProductLine::factory()->create(['slug' => 'pokemon-jp']);
    $pokeSet = Set::factory()->create(['product_line_id' => $poke->id]);
    $jpSet = Set::factory()->create(['product_line_id' => $jp->id]);
    makeCard($poke, $pokeSet, ['language' => 'en']);
    CatalogItem::factory()->create(['product_line_id' => $jp->id, 'set_id' => $jpSet->id, 'attributes' => ['language' => 'ja']]);

    $opts = app(CatalogFilterOptions::class)(['product_line' => 'pokemon']);

    expect($opts['languages'])->toContain('en')->not->toContain('ja');
});
