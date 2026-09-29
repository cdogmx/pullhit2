<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\CreateProvisionalCard;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Scanning\IdentifiedCard;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Trading cards that are not a game.
 *
 * Topps Chrome, Panini Prizm and the entertainment sets beside them are
 * collected and priced like TCG singles but have none of a game's structure.
 * What they have is PARALLELS: one card printed in a dozen finishes, each worth
 * something different — the same relationship holo/reverse_holo has in tcg.
 *
 * The two vocabularies must not mix. The first real scans of this kind came back
 * tagged "variant: holo", the game vocabulary forced onto a chrome refractor:
 * wrong, and valid enough to save.
 */
beforeEach(function () {
    $this->tcg = Vertical::firstOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
    $this->collectibles = Vertical::firstOrCreate(['slug' => 'collectibles'], ['name' => 'Collectibles']);
});

test('the vertical is registered with its own facets', function () {
    $keys = array_map(
        fn ($a) => $a->key,
        app(VerticalRegistry::class)->get('collectibles')->attributesFor('single'),
    );

    expect($keys)->toContain('parallel')
        ->toContain('manufacturer')
        ->toContain('autograph')
        // The game vocabulary has no place here.
        ->not->toContain('variant')
        ->not->toContain('edition');
});

test('a parallel is a printing, so it gets its own row under one base card', function () {
    $line = ProductLine::factory()->create(['vertical_id' => $this->collectibles->id, 'slug' => 'topps-chrome']);
    $set = Set::factory()->create(['product_line_id' => $line->id]);
    $create = app(CreateCatalogItem::class);

    $base = $create(
        vertical: $this->collectibles, productLine: $line, set: $set,
        itemType: ItemType::Single, name: 'Mickey Mouse', number: '014',
        attributes: ['language' => 'en'],
    );
    $refractor = $create(
        vertical: $this->collectibles, productLine: $line, set: $set,
        itemType: ItemType::Single, name: 'Mickey Mouse', number: '014',
        attributes: ['language' => 'en', 'parallel' => 'Gold Refractor'],
    );

    // Separate rows, because they are separate cards at separate prices…
    expect($refractor->id)->not->toBe($base->id)
        ->and($refractor->identity_hash)->not->toBe($base->identity_hash)
        // …grouped under one base card, because they are one card's printings.
        ->and($refractor->base_key)->toBe($base->base_key);
});

test('an autograph is a different card, not a condition of one', function () {
    $line = ProductLine::factory()->create(['vertical_id' => $this->collectibles->id, 'slug' => 'panini']);
    $set = Set::factory()->create(['product_line_id' => $line->id]);
    $create = app(CreateCatalogItem::class);

    $plain = $create(
        vertical: $this->collectibles, productLine: $line, set: $set,
        itemType: ItemType::Single, name: 'Dash Parr', number: '5',
        attributes: ['language' => 'en', 'autograph' => false],
    );
    $signed = $create(
        vertical: $this->collectibles, productLine: $line, set: $set,
        itemType: ItemType::Single, name: 'Dash Parr', number: '5',
        attributes: ['language' => 'en', 'autograph' => true],
    );

    // Identity-defining, so they do not even share a base card: a signed card
    // and its unsigned twin are not one card in two printings.
    expect($signed->id)->not->toBe($plain->id)
        ->and($signed->base_key)->not->toBe($plain->base_key);
});

test('a game facet is refused here', function () {
    $line = ProductLine::factory()->create(['vertical_id' => $this->collectibles->id, 'slug' => 'topps']);

    expect(fn () => app(CreateCatalogItem::class)(
        vertical: $this->collectibles, productLine: $line, set: null,
        itemType: ItemType::Single, name: 'Mickey Mouse', number: '014',
        attributes: ['language' => 'en', 'variant' => 'holo'],
    ))->toThrow(ValidationException::class);
});

test('a scanned Topps card lands in collectibles, not in the game vertical', function () {
    $card = app(CreateProvisionalCard::class)(IdentifiedCard::fromVision([
        'name' => 'Mickey Mouse', 'number' => '014/199',
        'set_name' => 'Topps Chrome Disney', 'game' => 'Disney Topps Chrome',
        'language' => 'en', 'variant' => 'holo', 'is_graded' => false, 'confidence' => 0.88,
    ]));

    expect($card)->not->toBeNull()
        ->and($card->vertical_id)->toBe($this->collectibles->id)
        // And the read's "holo" is dropped rather than written into the printing
        // axis: absent means base, and a reviewer can name the parallel.
        ->and($card->attributes)->not->toHaveKey('variant')
        ->and($card->attributes)->not->toHaveKey('parallel');
});

test('a scanned Pokemon card still lands in the game vertical', function () {
    $card = app(CreateProvisionalCard::class)(IdentifiedCard::fromVision([
        'name' => 'Some New Card', 'number' => '001',
        'set_name' => 'Some Set', 'game' => 'Pokemon',
        'language' => 'en', 'variant' => 'holo', 'is_graded' => false, 'confidence' => 0.9,
    ]));

    expect($card->vertical_id)->toBe($this->tcg->id)
        ->and($card->attributes['variant'])->toBe('holo');
});

test('a brand we already hold decides the vertical outright', function () {
    // No guessing needed once the brand exists: whichever vertical it is filed
    // under is the answer, however the read spelled the maker.
    ProductLine::factory()->create([
        'vertical_id' => $this->tcg->id, 'slug' => 'topps-tcg-oddity', 'name' => 'Topps TCG Oddity',
    ]);

    $card = app(CreateProvisionalCard::class)(IdentifiedCard::fromVision([
        'name' => 'Odd Card', 'number' => '7', 'set_name' => 'Some Set',
        'game' => 'Topps TCG Oddity', 'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ]));

    expect($card->vertical_id)->toBe($this->tcg->id);
});

test('a serial number is never stored as the collector number', function () {
    // The catalog-multiplying bug. "014/199" on a Topps card means the 14th of
    // 199 copies, and the number feeds identity_hash — so storing it would give
    // every copy its own row. 199 people scanning one card, 199 catalog rows.
    $card = app(CreateProvisionalCard::class)(IdentifiedCard::fromVision([
        'name' => 'Mickey Mouse', 'number' => '014/199',
        'set_name' => 'Chrome Disney 2026', 'game' => 'Topps',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ]));

    expect($card->number)->toBeNull()
        // Kept as what it actually is: how many were printed.
        ->and($card->attributes['print_run'])->toBe(199);
});

test('two copies of one serial-numbered card are one row', function () {
    $read = fn (string $serial) => IdentifiedCard::fromVision([
        'name' => 'Mickey Mouse', 'number' => $serial,
        'set_name' => 'Chrome Disney 2026', 'game' => 'Topps',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ]);

    $first = app(CreateProvisionalCard::class)($read('014/199'));
    $second = app(CreateProvisionalCard::class)($read('015/199'));

    expect($second->id)->toBe($first->id)
        ->and(CatalogItem::where('name', 'Mickey Mouse')->count())->toBe(1);
});

test('a real collector number still survives on a game single', function () {
    // The guard is scoped to collectibles: on a TCG single, N/M is exactly what
    // the field is for and must not be thrown away.
    $card = app(CreateProvisionalCard::class)(IdentifiedCard::fromVision([
        'name' => 'Some New Card', 'number' => '006/025',
        'set_name' => 'Some Set', 'game' => 'Pokemon',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ]));

    expect($card->number)->toBe('006/025');
});

test('the printing and the autograph are carried over when read', function () {
    $card = app(CreateProvisionalCard::class)(IdentifiedCard::fromVision([
        'name' => 'Dash Parr', 'set_name' => 'Disney Neon 2026', 'game' => 'Topps',
        'language' => 'en', 'parallel' => 'Gold Refractor', 'autograph' => true,
        'variant' => 'holo', 'is_graded' => false, 'confidence' => 0.9,
    ]));

    expect($card->attributes['parallel'])->toBe('Gold Refractor')
        ->and($card->attributes['autograph'])->toBeTrue()
        // The game vocabulary is still refused, however confidently it is read.
        ->and($card->attributes)->not->toHaveKey('variant');
});
