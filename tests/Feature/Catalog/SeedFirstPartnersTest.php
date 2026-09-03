<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
        'name' => 'Pokémon',
    ]);
});

/** The set the command should have built for a series. */
function fpSet(string $slug): ?Set
{
    return Set::where('slug', $slug)->first();
}

test('it creates the Series 3 set, its nine promos and the sealed collection', function () {
    $this->artisan('catalog:seed-first-partners', ['--series' => 3, '--execute' => true])
        ->assertSuccessful();

    $set = fpSet('first-partners-3');

    expect($set)->not->toBeNull()
        ->and($set->name)->toBe('Series 3')
        ->and($set->code)->toBe('PFP3')
        ->and($set->series)->toBe('First Partners')
        ->and($set->language)->toBe('en')
        ->and($set->released_at->toDateString())->toBe('2026-08-07');

    $cards = CatalogItem::where('set_id', $set->id)
        ->where('item_type', ItemType::Single)
        ->orderByRaw('CAST(number AS UNSIGNED)')
        ->pluck('name', 'number');

    expect($cards->all())->toBe([
        '55' => 'Treecko', '56' => 'Torchic', '57' => 'Mudkip',
        '58' => 'Chespin', '59' => 'Fennekin', '60' => 'Froakie',
        '61' => 'Sprigatito', '62' => 'Fuecoco', '63' => 'Quaxly',
    ]);

    expect(CatalogItem::where('set_id', $set->id)->where('item_type', ItemType::Sealed)->count())->toBe(1);
});

test('the promos are holo promos, which is what fills the rarity filter', function () {
    $this->artisan('catalog:seed-first-partners', ['--series' => 3, '--execute' => true]);

    $treecko = CatalogItem::where('name', 'Treecko')->firstOrFail();

    expect($treecko->getAttribute('attributes'))
        ->toEqual(['language' => 'en', 'variant' => 'holo', 'rarity' => 'Promo']);
});

test('the set slug continues the two already published, because it is the card URL', function () {
    // CreateSet would derive "series-3" from the name; these cards have to sit
    // beside /pokemon/first-partners and /pokemon/first-partners-2.
    $this->artisan('catalog:seed-first-partners', ['--series' => 3, '--execute' => true]);

    expect(fpSet('first-partners-3'))->not->toBeNull()
        ->and(Set::where('slug', 'series-3')->exists())->toBeFalse();
});

test('the sealed collection carries its upstream product id, msrp and release', function () {
    $this->artisan('catalog:seed-first-partners', ['--series' => 3, '--execute' => true]);

    $sealed = CatalogItem::where('item_type', ItemType::Sealed)->firstOrFail();

    expect($sealed->name)->toBe('First Partner Illustration Collection - Series 3')
        ->and($sealed->msrp)->toBe(1499)
        ->and($sealed->released_at->toDateString())->toBe('2026-08-07')
        ->and($sealed->external_ids['tcgplayer_product_id'])->toBe('695400')
        ->and($sealed->getAttribute('attributes'))
        ->toMatchArray(['sealed_type' => 'collection', 'pack_count' => '3']);
});

test('a dry run writes nothing', function () {
    $this->artisan('catalog:seed-first-partners', ['--series' => 3])->assertSuccessful();

    expect(Set::count())->toBe(0)
        ->and(CatalogItem::count())->toBe(0);
});

test('running it twice creates nothing the second time', function () {
    $this->artisan('catalog:seed-first-partners', ['--execute' => true])->assertSuccessful();

    $before = [
        'sets' => Set::count(),
        'items' => CatalogItem::count(),
        'touched' => CatalogItem::max('updated_at'),
    ];

    $this->artisan('catalog:seed-first-partners', ['--execute' => true])->assertSuccessful();

    expect(Set::count())->toBe($before['sets'])
        ->and(CatalogItem::count())->toBe($before['items'])
        ->and(CatalogItem::max('updated_at'))->toBe($before['touched']);
});

test('it leaves a card that already exists exactly as it found it', function () {
    // Series 1 and 2 have been trading for months and carry hand-uploaded images.
    // Re-running the command must not reach them.
    $set = Set::factory()->create([
        'product_line_id' => $this->line->id,
        'slug' => 'first-partners-3',
        'name' => 'Series 3',
    ]);

    $treecko = app(CreateCatalogItem::class)(
        vertical: $this->vertical,
        productLine: $this->line,
        set: $set,
        itemType: ItemType::Single,
        name: 'Treecko',
        number: '55',
        attributes: ['language' => 'en', 'variant' => 'holo', 'rarity' => 'Hand Checked'],
        primaryImagePath: 'https://example.test/treecko.jpg',
    );

    $this->artisan('catalog:seed-first-partners', ['--series' => 3, '--execute' => true]);

    $after = $treecko->fresh();

    expect($after->primary_image_path)->toBe('https://example.test/treecko.jpg')
        ->and($after->getAttribute('attributes')['rarity'])->toBe('Hand Checked')
        // …and the other eight still arrived.
        ->and(CatalogItem::where('set_id', $set->id)->where('item_type', ItemType::Single)->count())->toBe(9);
});

test('it fills only the gaps in a half-built series', function () {
    $set = Set::factory()->create([
        'product_line_id' => $this->line->id,
        'slug' => 'first-partners-3',
        'name' => 'Series 3',
    ]);

    foreach (['55' => 'Treecko', '56' => 'Torchic'] as $number => $name) {
        app(CreateCatalogItem::class)(
            vertical: $this->vertical,
            productLine: $this->line,
            set: $set,
            itemType: ItemType::Single,
            name: $name,
            number: $number,
            attributes: ['language' => 'en', 'variant' => 'holo', 'rarity' => 'Promo'],
        );
    }

    $this->artisan('catalog:seed-first-partners', ['--series' => 3, '--execute' => true]);

    expect(CatalogItem::where('set_id', $set->id)->where('item_type', ItemType::Single)->count())->toBe(9)
        ->and(Set::where('slug', 'first-partners-3')->count())->toBe(1);
});

test('every card across the three series has its own identity and number', function () {
    $this->artisan('catalog:seed-first-partners', ['--execute' => true])->assertSuccessful();

    $singles = CatalogItem::where('item_type', ItemType::Single)->get();

    expect($singles)->toHaveCount(27)
        // The numbering runs unbroken across the line, 37 to 63.
        ->and($singles->pluck('number')->map(fn ($n) => (int) $n)->sort()->values()->all())
        ->toBe(range(37, 63))
        ->and($singles->pluck('identity_hash')->unique())->toHaveCount(27);
});

test('it refuses a series that does not exist', function () {
    $this->artisan('catalog:seed-first-partners', ['--series' => 4])->assertFailed();

    expect(Set::count())->toBe(0);
});
