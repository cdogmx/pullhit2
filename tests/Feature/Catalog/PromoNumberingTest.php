<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\SearchCatalog;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\CatalogItemSlugAlias;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create([
        'vertical_id' => $this->vertical->id,
        'slug' => 'pokemon',
    ]);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->pokemon->id,
        'slug' => 'scarlet-violet-black-star-promos',
        'name' => 'Scarlet & Violet Black Star Promos',
        'code' => 'PR-SV',
        'language' => 'en',
    ]);
});

function promoCard(string $name, string $number): CatalogItem
{
    return app(CreateCatalogItem::class)(
        vertical: test()->vertical,
        productLine: test()->pokemon,
        set: test()->set,
        itemType: ItemType::Single,
        name: $name,
        number: $number,
        attributes: ['language' => 'en', 'variant' => 'holo', 'rarity' => 'Promo'],
    );
}

test('it prefixes bare promo numbers with the printed prefix', function () {
    $charizard = promoCard('Charizard ex', '74');
    $sprigatito = promoCard('Sprigatito', '1');

    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
        '--execute' => true,
    ])->assertSuccessful();

    // Printed as "SVP 074" — zero-padded, matching how SWSH050/XY01 are stored.
    expect($charizard->fresh()->number)->toBe('SVP074')
        ->and($sprigatito->fresh()->number)->toBe('SVP001');
});

test('the old URL redirects to the new one instead of 404ing', function () {
    $card = promoCard('Charizard ex', '74');
    $oldSlug = $card->slug;

    expect($oldSlug)->toBe('charizard-ex-74');

    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
        '--execute' => true,
    ])->assertSuccessful();

    $card->refresh();
    expect($card->slug)->toBe('charizard-ex-svp074');

    // The alias was recorded automatically by the model, not by the command.
    expect(CatalogItemSlugAlias::where('slug', $oldSlug)->where('set_id', $this->set->id)->exists())
        ->toBeTrue();

    $this->get("/pokemon/scarlet-violet-black-star-promos/{$oldSlug}")
        ->assertRedirect('/pokemon/scarlet-violet-black-star-promos/charizard-ex-svp074')
        ->assertStatus(301);
});

test('a genuinely unknown slug still 404s', function () {
    promoCard('Charizard ex', '74');

    $this->get('/pokemon/scarlet-violet-black-star-promos/no-such-card-999')
        ->assertNotFound();
});

test('it leaves already-prefixed numbers alone and is idempotent', function () {
    $card = promoCard('Pikachu', 'SVP050');

    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
        '--execute' => true,
    ])->assertSuccessful();

    expect($card->fresh()->number)->toBe('SVP050');

    // Re-running must not produce SVPSVP050.
    $bare = promoCard('Charizard ex', '74');
    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
        '--execute' => true,
    ])->assertSuccessful();
    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
        '--execute' => true,
    ])->assertSuccessful();

    expect($bare->fresh()->number)->toBe('SVP074');
});

test('a dry run writes nothing', function () {
    $card = promoCard('Charizard ex', '74');

    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
    ])->assertSuccessful();

    expect($card->fresh()->number)->toBe('74')
        ->and(CatalogItemSlugAlias::count())->toBe(0);
});

test('the renumbered card is findable by its printed number', function () {
    promoCard('Charizard ex', '74');

    $this->artisan('catalog:prefix-promo-numbers', [
        'set' => 'scarlet-violet-black-star-promos',
        '--execute' => true,
    ])->assertSuccessful();

    $search = app(SearchCatalog::class);

    // The whole point: "SVP 074" and "SVP074" used to return nothing.
    expect($search(['q' => 'SVP074'])->total())->toBe(1)
        ->and($search(['q' => 'SVP 074'])->total())->toBe(1)
        ->and($search(['q' => 'Charizard SVP074'])->total())->toBe(1);
});

test('a promo prefix matches the number, without a stray letter widening the search', function () {
    // Two cards that differ only in how they are numbered.
    promoCard('Charizard ex', 'SVP074');
    promoCard('Pikachu', '25');

    $search = app(SearchCatalog::class);

    // The prefix resolves even though no name or set name contains "SVP".
    expect($search(['q' => 'SVP 074'])->total())->toBe(1);

    // "V" is one letter, so it must NOT match every number containing a V —
    // otherwise "Charizard V" would drag in the whole SVP set.
    $vHits = $search(['q' => 'Charizard V'])->items();
    expect($vHits)->toHaveCount(1)
        ->and($vHits[0]->name)->toBe('Charizard ex');

    // The prefix is anchored: "vp" sits inside SVP074 but does not start it.
    // (A token carrying a digit still does a substring match on the number —
    // that predates this and is what makes a bare "074" work.)
    expect($search(['q' => 'vp'])->total())->toBe(0);
});
