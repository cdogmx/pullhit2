<?php

use App\Actions\Import\MatchPricechartingRow;
use App\Actions\Import\ParsePricechartingCsv;
use App\Models\CatalogItem;
use App\Models\Set;

/** Parse a single PriceCharting row from a minimal CSV. */
function pcRow(string $product, string $console, string $include = 'Ungraded'): object
{
    $csv = "id,product-name,console-name,include-string,quantity,grading-company\n"
        ."1,{$product},{$console},{$include},1,";

    return (new ParsePricechartingCsv)($csv)[0];
}

beforeEach(function () {
    $this->set = Set::factory()->create(['language' => 'en', 'name' => 'Surging Sparks']);
    $this->normal = CatalogItem::factory()->for($this->set)->create([
        'name' => 'Pikachu', 'number' => '25', 'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);
    $this->reverse = CatalogItem::factory()->for($this->set)->create([
        'name' => 'Pikachu', 'number' => '25', 'attributes' => ['language' => 'en', 'variant' => 'reverse'],
    ]);
});

test('a row with no variant matches the base printing, not the reverse', function () {
    $result = (new MatchPricechartingRow)(pcRow('Pikachu #25', 'Pokemon Surging Sparks'));

    expect($result->status)->toBe('matched')
        ->and($result->catalogItem->id)->toBe($this->normal->id);
});

test('a [Reverse] row matches the reverse printing', function () {
    $result = (new MatchPricechartingRow)(pcRow('Pikachu [Reverse] #25', 'Pokemon Surging Sparks'));

    expect($result->status)->toBe('matched')
        ->and($result->catalogItem->id)->toBe($this->reverse->id);
});

test('a language we have no sets for is unmatched with a clear reason', function () {
    $result = (new MatchPricechartingRow)(pcRow('Pikachu #25', 'Pokemon Japanese Nihil Zero'));

    expect($result->status)->toBe('unmatched')
        ->and($result->reason)->toContain('ja');
});

test('an unknown set in a known language is unmatched', function () {
    $result = (new MatchPricechartingRow)(pcRow('Beautifly #219', 'Pokemon Ascended Heroes'));

    expect($result->status)->toBe('unmatched')
        ->and($result->reason)->toBe('no_set_match');
});
