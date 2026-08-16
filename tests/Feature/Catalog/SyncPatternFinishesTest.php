<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\SearchCatalog;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\CatalogItemSlugAlias;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
    ]);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->pokemon->id,
        'slug' => 'ascended-heroes',
        'name' => 'Ascended Heroes',
        'code' => 'ASC',
        'language' => 'en',
        'external_ids' => ['tcgplayer_group_id' => '24541'],
    ]);

    // Upstream names each pattern; we flattened them on the way in.
    Http::fake([
        'tcgcsv.com/tcgplayer/3/24541/products' => Http::response(['results' => [
            upstreamCard(1, "Erika's Oddish (Poke Ball)"),
            upstreamCard(2, 'Hitmontop (Dusk Ball)'),
            upstreamCard(3, 'Meditite (Love Ball)'),
            upstreamCard(18, "Team Rocket's Tarountula (Team Rocket)"),
            // Not printings — product tags that must never become a finish.
            upstreamCard(4, 'Pikachu (Sam\'s Club)'),
            upstreamCard(5, 'Snorlax (Exclusive)'),
        ]]),
    ]);
});

function upstreamCard(int $number, string $name): array
{
    return [
        'productId' => 700000 + $number,
        'name' => $name,
        'extendedData' => [
            ['name' => 'Number', 'value' => str_pad((string) $number, 3, '0', STR_PAD_LEFT).'/217'],
            ['name' => 'Rarity', 'value' => 'Common'],
        ],
    ];
}

function ascCard(string $name, string $number, array $attributes): CatalogItem
{
    return app(CreateCatalogItem::class)(
        vertical: test()->vertical,
        productLine: test()->pokemon,
        set: test()->set,
        itemType: ItemType::Single,
        name: $name,
        number: $number,
        attributes: ['language' => 'en'] + $attributes,
    );
}

test('it splits a flattened ball finish into the pattern actually printed', function () {
    $oddish = ascCard("Erika's Oddish", '1', ['variant' => 'normal', 'finish' => 'ball']);
    $hitmontop = ascCard('Hitmontop', '2', ['variant' => 'normal', 'finish' => 'ball']);
    $meditite = ascCard('Meditite', '3', ['variant' => 'normal', 'finish' => 'ball']);

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    expect($oddish->fresh()->getAttribute('attributes')['finish'])->toBe('poke_ball')
        ->and($hitmontop->fresh()->getAttribute('attributes')['finish'])->toBe('dusk_ball')
        ->and($meditite->fresh()->getAttribute('attributes')['finish'])->toBe('love_ball');

    // The display name is what a collector actually reads on the page.
    expect($oddish->fresh()->display_name)->toBe("Erika's Oddish (Poke Ball)");
});

test('it moves the Team Rocket pattern off the plain reverse-holo slot', function () {
    $tr = ascCard("Team Rocket's Tarountula", '18', ['variant' => 'reverse_holo']);

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    $attributes = $tr->fresh()->getAttribute('attributes');

    expect($attributes['finish'])->toBe('team_rocket')
        ->and($attributes['variant'])->toBe('normal')
        ->and($tr->fresh()->display_name)->toBe("Team Rocket's Tarountula (Team Rocket)");
});

test('it leaves a genuine reverse holo alone', function () {
    // #2 upstream is a Dusk Ball, not a Team Rocket printing, so a reverse holo
    // on that number is a real reverse holo and must not be relabelled.
    $rh = ascCard('Hitmontop', '2', ['variant' => 'reverse_holo']);

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    $attributes = $rh->fresh()->getAttribute('attributes');

    expect($attributes['variant'])->toBe('reverse_holo')
        ->and($attributes['finish'] ?? null)->toBeNull();
});

test('it ignores retailer tags that are not printings', function () {
    $pikachu = ascCard('Pikachu', '4', ['variant' => 'normal', 'finish' => 'ball']);
    $snorlax = ascCard('Snorlax', '5', ['variant' => 'normal', 'finish' => 'ball']);

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    // "Sam's Club" and "Exclusive" describe where a box was sold, not a pattern.
    expect($pikachu->fresh()->getAttribute('attributes')['finish'])->toBe('ball')
        ->and($snorlax->fresh()->getAttribute('attributes')['finish'])->toBe('ball');
});

test('it leaves the energy pattern and other finishes untouched', function () {
    $energy = ascCard("Erika's Oddish", '1', ['variant' => 'normal', 'finish' => 'energy']);
    $cosmos = ascCard('Hitmontop', '2', ['variant' => 'normal', 'finish' => 'cosmos_holo']);

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    expect($energy->fresh()->getAttribute('attributes')['finish'])->toBe('energy')
        ->and($cosmos->fresh()->getAttribute('attributes')['finish'])->toBe('cosmos_holo');
});

test('the old URL keeps working after the relabel', function () {
    $oddish = ascCard("Erika's Oddish", '1', ['variant' => 'normal', 'finish' => 'ball']);
    $oldSlug = $oddish->slug;

    expect($oldSlug)->toBe('erikas-oddish-ball-1');

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    expect($oddish->fresh()->slug)->toBe('erikas-oddish-poke-ball-1')
        ->and(CatalogItemSlugAlias::where('slug', $oldSlug)->exists())->toBeTrue();

    $this->get("/pokemon/ascended-heroes/{$oldSlug}")
        ->assertRedirect('/pokemon/ascended-heroes/erikas-oddish-poke-ball-1')
        ->assertStatus(301);
});

test('a dry run writes nothing', function () {
    $oddish = ascCard("Erika's Oddish", '1', ['variant' => 'normal', 'finish' => 'ball']);

    $this->artisan('catalog:sync-pattern-finishes', ['set' => 'ascended-heroes'])
        ->assertSuccessful();

    expect($oddish->fresh()->getAttribute('attributes')['finish'])->toBe('ball');
});

test('it is idempotent', function () {
    $oddish = ascCard("Erika's Oddish", '1', ['variant' => 'normal', 'finish' => 'ball']);

    foreach (range(1, 3) as $ignored) {
        $this->artisan('catalog:sync-pattern-finishes', [
            'set' => 'ascended-heroes',
            '--execute' => true,
        ])->assertSuccessful();
    }

    expect($oddish->fresh()->getAttribute('attributes')['finish'])->toBe('poke_ball')
        ->and(CatalogItem::where('set_id', $this->set->id)->count())->toBe(1);
});

test('a relabelled printing is findable by the pattern printed on it', function () {
    ascCard("Erika's Oddish", '1', ['variant' => 'normal', 'finish' => 'ball']);
    ascCard("Erika's Oddish", '1', ['variant' => 'normal']); // the plain print
    ascCard('Hitmontop', '2', ['variant' => 'normal', 'finish' => 'ball']);

    $this->artisan('catalog:sync-pattern-finishes', [
        'set' => 'ascended-heroes',
        '--execute' => true,
    ])->assertSuccessful();

    $search = app(SearchCatalog::class);

    // The pattern is only visible through the display name; the `name` column
    // is just "Erika's Oddish", so these all used to return nothing.
    expect($search(['q' => "Erika's Oddish Poke Ball"])->total())->toBe(1)
        ->and($search(['q' => 'Hitmontop Dusk Ball'])->total())->toBe(1)
        ->and($search(['q' => 'Poke Ball Ascended Heroes'])->total())->toBe(1);

    // The plain print is still reachable and is not dragged in by the pattern.
    expect($search(['q' => "Erika's Oddish"])->total())->toBe(2);
});
