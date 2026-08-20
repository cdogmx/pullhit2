<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'one-piece',
        'name' => 'One Piece Card Game',
    ]);

    Http::fake([
        'tcgcsv.com/tcgplayer/68/groups' => Http::response(['results' => [
            ['groupId' => 23387, 'name' => '500 Years in the Future', 'abbreviation' => 'OP07', 'categoryId' => 68],
            // TCGplayer punctuates its starter-deck codes; we do not.
            ['groupId' => 24284, 'name' => 'Starter Deck 25: BLUE Buggy', 'abbreviation' => 'ST-25', 'categoryId' => 68],
            ['groupId' => 17675, 'name' => 'One Piece Promotion Cards', 'abbreviation' => 'OP-PR', 'categoryId' => 68],
        ]]),
        'tcgcsv.com/tcgplayer/68/23387/products' => Http::response(['results' => [
            upstreamOpCard('OP07-001', 'Monkey.D.Dragon', 'L', 'Red'),
            upstreamOpCard('OP07-002', 'Ain', 'R', 'Red'),
            // A parallel printing repeats the number; it describes the same card.
            upstreamOpCard('OP07-002', 'Ain (Parallel)', 'R', 'Red'),
            upstreamOpCard('OP07-003', 'Outlook III', 'SEC', 'Blue'),
        ]]),
        'tcgcsv.com/tcgplayer/68/24284/products' => Http::response(['results' => [
            upstreamOpCard('ST25-001', 'Buggy', 'L', 'Blue'),
        ]]),
        'tcgcsv.com/tcgplayer/68/17675/products' => Http::response(['results' => [
            upstreamOpCard('P-001', 'Monkey.D.Luffy', 'P', 'Red'),
        ]]),
    ]);
});

function upstreamOpCard(string $number, string $name, string $rarity, string $color): array
{
    return [
        'productId' => crc32($number.$name),
        'name' => $name,
        'extendedData' => [
            ['name' => 'Number', 'value' => $number],
            ['name' => 'Rarity', 'value' => $rarity],
            ['name' => 'Color', 'value' => $color],
        ],
    ];
}

function factsSet(string $name, ?string $code, array $extra = []): Set
{
    return Set::factory()->create(array_merge([
        'product_line_id' => test()->line->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::random(4),
        'code' => $code,
        'language' => 'en',
    ], $extra));
}

function factsItem(Set $set, string $name, string $number, array $attributes = []): CatalogItem
{
    return app(CreateCatalogItem::class)(
        vertical: test()->vertical,
        productLine: test()->line,
        set: $set,
        itemType: ItemType::Single,
        name: $name,
        number: $number,
        attributes: array_merge(['language' => 'en', 'variant' => 'normal'], $attributes),
    );
}

test('it fills the rarity and type a price-only source never supplied', function () {
    $set = factsSet('500 Years in the Future', 'OP07');
    $dragon = factsItem($set, 'Monkey.D.Dragon', 'OP07-001');
    $ain = factsItem($set, 'Ain', 'OP07-002');

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    expect($dragon->fresh()->getAttribute('attributes'))
        ->toMatchArray(['rarity' => 'L', 'type' => 'Red'])
        ->and($ain->fresh()->getAttribute('attributes'))
        ->toMatchArray(['rarity' => 'R', 'type' => 'Red']);
});

test('it updates in place — no identity, slug or URL moves, and nothing is inserted', function () {
    $set = factsSet('500 Years in the Future', 'OP07');
    $card = factsItem($set, 'Ain', 'OP07-002');

    $before = [
        'identity' => $card->identity_hash,
        'base' => $card->base_key,
        'slug' => $card->slug,
        'display' => $card->display_name,
        'count' => CatalogItem::count(),
    ];

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    $after = $card->fresh();

    // Rarity and type are descriptive, not identity-defining — this is the
    // property that lets a backfill run safely where an import would duplicate.
    expect($after->identity_hash)->toBe($before['identity'])
        ->and($after->base_key)->toBe($before['base'])
        ->and($after->slug)->toBe($before['slug'])
        ->and($after->display_name)->toBe($before['display'])
        ->and(CatalogItem::count())->toBe($before['count']);
});

test('it fills blanks but leaves curated values alone', function () {
    $set = factsSet('500 Years in the Future', 'OP07');
    $curated = factsItem($set, 'Ain', 'OP07-002', ['rarity' => 'Hand Checked']);

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    expect($curated->fresh()->getAttribute('attributes'))
        // Kept, but the missing type still gets filled.
        ->toMatchArray(['rarity' => 'Hand Checked', 'type' => 'Red']);

    $this->artisan('catalog:backfill-card-facts', [
        '--line' => 'one-piece', '--overwrite' => true, '--execute' => true,
    ])->assertSuccessful();

    expect($curated->fresh()->getAttribute('attributes')['rarity'])->toBe('R');
});

test('a set code matches however either side punctuates it', function () {
    // We store "ST25"; TCGplayer publishes "ST-25".
    $set = factsSet('Starter Deck 25 Blue Buggy', 'ST25');
    $buggy = factsItem($set, 'Buggy', 'ST25-001');

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    expect($buggy->fresh()->getAttribute('attributes'))
        ->toMatchArray(['rarity' => 'L', 'type' => 'Blue']);
});

test('a recorded group id wins over the name, so a renamed set still resolves', function () {
    // Our "Promo" is upstream's "One Piece Promotion Cards" — neither the name
    // nor the code "P" would ever match, so the link is recorded once by hand.
    $set = factsSet('Promo', 'P', ['external_ids' => ['tcgplayer_group_id' => '17675']]);
    $luffy = factsItem($set, 'Monkey.D.Luffy', 'P-001');

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    expect($luffy->fresh()->getAttribute('attributes'))
        ->toMatchArray(['rarity' => 'P', 'type' => 'Red']);
});

test('it records the group id it matched, so the next run skips the guessing', function () {
    $set = factsSet('500 Years in the Future', 'OP07');
    factsItem($set, 'Ain', 'OP07-002');

    expect($set->external_ids['tcgplayer_group_id'] ?? null)->toBeNull();

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    expect($set->fresh()->external_ids['tcgplayer_group_id'])->toBe('23387');
});

test('a card with no upstream counterpart is left untouched', function () {
    $set = factsSet('500 Years in the Future', 'OP07');
    $orphan = factsItem($set, 'Double Pack', 'DP-04');

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece', '--execute' => true])
        ->assertSuccessful();

    expect($orphan->fresh()->getAttribute('attributes'))
        ->not->toHaveKey('rarity')
        ->not->toHaveKey('type');
});

test('a dry run writes nothing', function () {
    $set = factsSet('500 Years in the Future', 'OP07');
    $card = factsItem($set, 'Ain', 'OP07-002');

    $this->artisan('catalog:backfill-card-facts', ['--line' => 'one-piece'])->assertSuccessful();

    expect($card->fresh()->getAttribute('attributes'))->not->toHaveKey('rarity')
        ->and($set->fresh()->external_ids['tcgplayer_group_id'] ?? null)->toBeNull();
});

test('it refuses a product line TCGCSV does not carry', function () {
    $this->artisan('catalog:backfill-card-facts', ['--line' => 'riftbound'])
        ->assertFailed();
});
