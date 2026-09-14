<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'tcgcsv.com/tcgplayer/3/groups' => Http::response(['results' => [
            ['groupId' => 24451, 'name' => 'ME: Mega Evolution Promo', 'abbreviation' => 'MEP', 'categoryId' => 3],
        ]]),
        'tcgcsv.com/tcgplayer/3/24451/products' => Http::response(['results' => [
            mepProduct(664010, 'Oricorio ex - 024', '024', 'Promo'),
            mepProduct(664011, 'Pikachu ex - 001', '001', 'Promo'),
            // Already ours: these live in the First Partners sets.
            mepProduct(664037, 'Bulbasaur - 037', '037', 'Promo'),
            mepProduct(664055, 'Treecko - 055', '055', 'Promo'),
            mepProduct(664063, 'Quaxly - 063', '063', 'Promo'),
        ]]),
        'tcgcsv.com/tcgplayer/3/24451/prices' => Http::response(['results' => []]),
    ]);
});

function mepProduct(int $id, string $name, string $number, string $rarity): array
{
    return [
        'productId' => $id,
        'name' => $name,
        'imageUrl' => null,
        'extendedData' => [
            ['name' => 'Number', 'value' => $number],
            ['name' => 'Rarity', 'value' => $rarity],
        ],
    ];
}

/**
 * The line the importer itself would create, made up front so a sibling set can
 * exist before the import runs — the series is read off the sets already there.
 */
function mepLine(): ProductLine
{
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);

    return ProductLine::factory()->create([
        'vertical_id' => $vertical->id,
        'slug' => 'pokemon',
        'name' => 'Pokémon',
    ]);
}

test('a skipped range is left out of the import', function () {
    // The overlap is a card we already hold elsewhere with user collections
    // against it. Importing it again would make a second copy, because identity
    // includes the set.
    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451],
        '--skip-numbers' => '037-063',
        '--no-images' => true,
        '--no-prices' => true,
    ])->assertSuccessful();

    $numbers = CatalogItem::pluck('number')->sort()->values()->all();

    expect($numbers)->toBe(['1', '24'])
        ->and(CatalogItem::where('name', 'Bulbasaur')->exists())->toBeFalse()
        ->and(CatalogItem::where('name', 'Treecko')->exists())->toBeFalse()
        // TCGplayer calls it "Oricorio ex - 024"; the trailing collector number
        // is stripped so it reads like the rest of the catalog.
        ->and(CatalogItem::where('name', 'Oricorio ex')->exists())->toBeTrue()
        ->and(CatalogItem::where('name', 'like', '%- 024')->exists())->toBeFalse();
});

test('the skip accepts padded and unpadded numbers alike', function () {
    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451],
        // "55" and "037" mean the same cards as "055" and "37".
        '--skip-numbers' => '37,55,63',
        '--no-images' => true,
        '--no-prices' => true,
    ])->assertSuccessful();

    expect(CatalogItem::pluck('number')->sort()->values()->all())->toBe(['1', '24']);
});

test('without the option the whole group comes in', function () {
    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451],
        '--no-images' => true,
        '--no-prices' => true,
    ])->assertSuccessful();

    expect(CatalogItem::count())->toBe(5);
});

test('the set is created from the upstream group', function () {
    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451],
        '--skip-numbers' => '037-063',
        '--no-images' => true,
        '--no-prices' => true,
    ])->assertSuccessful();

    $set = Set::firstOrFail();

    expect($set->name)->toContain('Mega Evolution Promo')
        ->and($set->external_ids['tcgplayer_group_id'] ?? null)->toBe('24451');
});

test('a set with no series is given the era its code prefix already belongs to', function () {
    // Browse drills brand → series → set, so a set with no series hangs off no
    // tile: importing this one put 184 cards in the catalog that could not be
    // reached from the Pokemon browse page at all.
    $line = mepLine();
    Set::factory()->create([
        'product_line_id' => $line->id, 'code' => 'ME05',
        'name' => 'Pitch Black', 'series' => 'Mega Evolution',
    ]);

    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451], '--no-images' => true, '--no-prices' => true,
    ])->assertSuccessful();

    expect(Set::where('code', 'ME')->firstOrFail()->series)->toBe('Mega Evolution');
});

test('it declines when the prefix spans two eras', function () {
    // "ME%" also matches "MEW", which is Scarlet & Violet 151. Filing a set
    // under the wrong era hides it as thoroughly as leaving it blank, and less
    // visibly — so an ambiguous prefix gets no answer at all.
    $line = mepLine();
    Set::factory()->create([
        'product_line_id' => $line->id, 'code' => 'ME05',
        'name' => 'Pitch Black', 'series' => 'Mega Evolution',
    ]);
    Set::factory()->create([
        'product_line_id' => $line->id, 'code' => 'ME99',
        'name' => 'Something Else', 'series' => 'Scarlet & Violet',
    ]);

    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451], '--no-images' => true, '--no-prices' => true,
    ])->assertSuccessful();

    expect(Set::where('code', 'ME')->firstOrFail()->series)->toBeNull();
});

test('a series the per-game importer already set is never overwritten', function () {
    $line = mepLine();
    Set::factory()->create([
        'product_line_id' => $line->id, 'code' => 'ME05',
        'name' => 'Pitch Black', 'series' => 'Mega Evolution',
    ]);
    Set::factory()->create([
        'product_line_id' => $line->id,
        'slug' => 'mega-evolution-promo',
        'name' => 'Mega Evolution Promo',
        'code' => 'MEP',
        'series' => 'Hand Curated',
        'external_ids' => ['tcgplayer_group_id' => '24451'],
    ]);

    $this->artisan('catalog:import-tcgcsv', [
        'groupIds' => [24451], '--no-images' => true, '--no-prices' => true,
    ])->assertSuccessful();

    expect(Set::where('slug', 'mega-evolution-promo')->firstOrFail()->series)->toBe('Hand Curated');
});
