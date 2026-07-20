<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\SearchCatalog;
use App\Actions\Catalog\SuggestSearch;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

beforeEach(function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->create(['vertical_id' => $vertical->id, 'slug' => 'pokemon', 'name' => 'Pokémon']);
    $set = Set::factory()->create(['product_line_id' => $line->id, 'slug' => 'base', 'code' => 'BS', 'language' => 'en']);
    $create = app(CreateCatalogItem::class);

    foreach ([['Pikachu', '58'], ['Charizard', '4'], ['Blastoise', '9']] as [$name, $number]) {
        $create(
            vertical: $vertical, productLine: $line, set: $set,
            itemType: ItemType::Single, name: $name, number: $number,
            attributes: ['language' => 'en', 'variant' => 'normal'],
        );
    }
});

test('a bare LIKE wildcard does not match the whole catalog', function () {
    // Regression: "%" fell straight into `LIKE '%%%'`, which matches every row —
    // a full-catalog scan a user could trigger with one keystroke.
    $search = app(SearchCatalog::class);

    expect($search(['q' => '%'])->total())->toBe(0)
        ->and($search(['q' => '_'])->total())->toBe(0)
        ->and($search(['q' => '%%%'])->total())->toBe(0);
});

test('wildcards embedded in a real term are treated as literals, not operators', function () {
    $search = app(SearchCatalog::class);

    // "pik%" has its "%" stripped, leaving "pik" — which still finds Pikachu.
    // But "pikax%" strips to "pikax", a literal no card contains: if "%" still
    // acted as "match anything" this would return rows. It must find nothing.
    expect($search(['q' => 'pik%'])->total())->toBe(1)
        ->and($search(['q' => 'pikax%'])->total())->toBe(0);
});

test('suggest ignores wildcards too', function () {
    $suggest = app(SuggestSearch::class);

    $all = $suggest('%');
    expect($all['cards'])->toBe([])
        ->and($all['sets'])->toBe([])
        ->and($all['brands'])->toBe([]);

    // A real prefix still works with a trailing wildcard stripped.
    $hit = $suggest('char%');
    expect(collect($hit['cards'])->pluck('name'))->toContain('Charizard');
});

test('suggest results are cached so a repeated prefix does not re-hit the DB', function () {
    $suggest = app(SuggestSearch::class);

    $first = $suggest('pikachu');
    expect(collect($first['cards'])->pluck('name'))->toContain('Pikachu');

    // Add a card that would match — but the cached response must not see it yet.
    app(CreateCatalogItem::class)(
        vertical: Vertical::first(), productLine: ProductLine::first(), set: Set::first(),
        itemType: ItemType::Single, name: 'Pikachu VMAX', number: '999',
        attributes: ['language' => 'en', 'variant' => 'normal'],
    );

    $cached = $suggest('pikachu');
    expect(collect($cached['cards'])->pluck('name'))->not->toContain('Pikachu VMAX');
});
