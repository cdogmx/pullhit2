<?php

use App\Actions\Catalog\ImportToppsSet;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

/**
 * Building a collectibles set from its published checklist and odds.
 *
 * Topps ships both as PDFs per release, and between them they describe the whole
 * product. The import creates a row per PRINTING rather than per card, because a
 * Gold Refractor and its base card are different cards at different prices —
 * 2026 Disney Chrome is 534 subjects and 8,069 printings.
 */
function toppsFixture(array $overrides = []): array
{
    return array_merge([
        'brand' => 'Topps',
        'set' => ['name' => 'Disney Chrome 2026', 'slug' => 'topps-disney-chrome-2026', 'language' => 'en'],
        'subsets' => [
            [
                'key' => 'base',
                'name' => 'Base',
                'parallels' => [
                    ['name' => 'Refractor', 'odds' => ['hobby' => '1:2']],
                    ['name' => 'Gold Refractor', 'odds' => ['hobby' => '1:163']],
                ],
                'cards' => [
                    ['number' => '50', 'name' => 'Mickey Mouse', 'franchise' => 'Mickey & Friends'],
                    ['number' => '150', 'name' => 'Stitch', 'franchise' => 'Lilo & Stitch'],
                ],
            ],
            [
                'key' => 'iconic-moments',
                'name' => 'Iconic Moments',
                'parallels' => [['name' => 'Red Refractor']],
                'cards' => [
                    ['number' => 'IM-5', 'name' => 'A Whole New World', 'franchise' => 'Aladdin'],
                ],
            ],
        ],
    ], $overrides);
}

test('it creates a row for the base card and one for every parallel', function () {
    $result = app(ImportToppsSet::class)(toppsFixture());

    // 2 base cards x (base + 2 parallels) + 1 insert x (base + 1 parallel).
    expect($result['cards'])->toBe(3)
        ->and($result['printings'])->toBe(8)
        ->and(CatalogItem::count())->toBe(8);

    $mickey = CatalogItem::where('name', 'Mickey Mouse')->get();

    expect($mickey)->toHaveCount(3)
        ->and($mickey->pluck('attributes.parallel')->filter()->sort()->values()->all())
        ->toBe(['Gold Refractor', 'Refractor']);
});

test('the base printing carries no parallel at all', function () {
    app(ImportToppsSet::class)(toppsFixture());

    $base = CatalogItem::where('name', 'Mickey Mouse')
        ->get()
        ->first(fn ($c) => ! isset($c->attributes['parallel']));

    // Absent rather than "Base": a placeholder hashes differently from an
    // absent facet, and the official importer would never match it.
    expect($base)->not->toBeNull()
        ->and($base->number)->toBe('50')
        ->and($base->attributes)->not->toHaveKey('parallel');
});

test('printings of one card group under a single base card', function () {
    app(ImportToppsSet::class)(toppsFixture());

    $keys = CatalogItem::where('name', 'Mickey Mouse')->pluck('base_key')->unique();

    // parallel is variant-defining, so the printings share a base_key and the
    // catalog can collapse them under one card.
    expect($keys)->toHaveCount(1);
});

test('an insert records which insert it is, and the base set does not', function () {
    app(ImportToppsSet::class)(toppsFixture());

    $insert = CatalogItem::where('number', 'IM-5')->first();
    $base = CatalogItem::where('number', '50')->first();

    expect($insert->attributes['rarity'])->toBe('Iconic Moments')
        // "Base" on every base card would be noise.
        ->and($base->attributes)->not->toHaveKey('rarity');
});

test('an autograph subset is flagged, so it never collides with the unsigned card', function () {
    $data = toppsFixture();
    $data['subsets'][] = [
        'key' => 'authentic-autographs',
        'name' => 'Authentic Autographs',
        'autograph' => true,
        'parallels' => [],
        'cards' => [['number' => 'AA-TH', 'name' => 'Tom Hanks', 'franchise' => 'Toy Story']],
    ];

    app(ImportToppsSet::class)($data);

    expect(CatalogItem::where('number', 'AA-TH')->first()->attributes['autograph'])->toBeTrue();
});

test('--base-only skips the parallels', function () {
    $result = app(ImportToppsSet::class)(toppsFixture(), withParallels: false);

    expect($result['printings'])->toBe(3)
        ->and(CatalogItem::count())->toBe(3);
});

test('re-running changes nothing', function () {
    // CreateCatalogItem upserts on identity_hash, so a correction can be
    // re-imported without doubling the set — the mistake that put 2,590
    // duplicate rows in Lorcana.
    app(ImportToppsSet::class)(toppsFixture());
    $first = CatalogItem::count();

    app(ImportToppsSet::class)(toppsFixture());

    expect(CatalogItem::count())->toBe($first);
});

test('it files the set under collectibles, not under the game vertical', function () {
    app(ImportToppsSet::class)(toppsFixture());

    $set = Set::where('slug', 'topps-disney-chrome-2026')->first();
    $line = ProductLine::find($set->product_line_id);

    expect($line->name)->toBe('Topps')
        ->and($line->vertical_id)->toBe(Vertical::where('slug', 'collectibles')->first()->id);
});
