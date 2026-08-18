<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\SearchCatalog;
use App\Enums\Condition;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon']);
    $this->set = Set::factory()->create(['product_line_id' => $this->line->id, 'language' => 'en']);
    $this->create = app(CreateCatalogItem::class);
    $this->user = User::factory()->create();
});

function ownCard(string $name, string $number = '1', string $variant = 'normal'): CatalogItem
{
    return (test()->create)(
        vertical: test()->vertical, productLine: test()->line, set: test()->set,
        itemType: ItemType::Single, name: $name, number: $number,
        attributes: ['language' => 'en', 'variant' => $variant],
    );
}

function addToCollection(User $user, CatalogItem $item): void
{
    $user->collectionItems()->create([
        'collection_id' => $user->defaultCollection()->id,
        'catalog_item_id' => $item->id,
        'condition' => Condition::NearMint,
        'quantity' => 1,
    ]);
}

function ids(iterable $paginator): array
{
    return collect($paginator->items())->pluck('id')->sort()->values()->all();
}

test('owned returns only cards the user has in a collection', function () {
    $owned = ownCard('Pikachu');
    ownCard('Charizard'); // not owned

    addToCollection($this->user, $owned);

    $res = app(SearchCatalog::class)(['owned' => 'owned', 'user_id' => $this->user->id]);

    expect(ids($res))->toBe([$owned->id]);
});

test('unowned returns only cards the user does NOT have', function () {
    $owned = ownCard('Pikachu');
    $notOwned = ownCard('Charizard');

    addToCollection($this->user, $owned);

    $res = app(SearchCatalog::class)(['owned' => 'unowned', 'user_id' => $this->user->id]);

    expect(ids($res))->toBe([$notOwned->id]);
});

test('all (no ownership filter) returns everything', function () {
    $a = ownCard('Pikachu');
    $b = ownCard('Charizard');
    addToCollection($this->user, $a);

    $res = app(SearchCatalog::class)(['user_id' => $this->user->id]);

    expect(ids($res))->toBe([$a->id, $b->id]);
});

test('owning ANY printing marks the whole card owned (base_key match)', function () {
    // Two printings of one card (normal + holo) share a base_key.
    $normal = ownCard('Pikachu', '1', 'normal');
    $holo = ownCard('Pikachu', '1', 'holo');
    expect($normal->base_key)->toBe($holo->base_key);

    // Own only the holo — the normal printing must still count as "owned".
    addToCollection($this->user, $holo);

    $owned = app(SearchCatalog::class)(['owned' => 'owned', 'user_id' => $this->user->id]);
    expect(ids($owned))->toBe([$normal->id, $holo->id]);

    // ...and neither shows under "unowned".
    $unowned = app(SearchCatalog::class)(['owned' => 'unowned', 'user_id' => $this->user->id]);
    expect(ids($unowned))->toBe([]);
});

test('the filter is a no-op for guests (no user id)', function () {
    $a = ownCard('Pikachu');
    $b = ownCard('Charizard');
    addToCollection($this->user, $a);

    // owned requested but no user_id (a guest) → no filtering, all cards returned.
    $res = app(SearchCatalog::class)(['owned' => 'owned']);

    expect(ids($res))->toBe([$a->id, $b->id]);
});

test('one user\'s collection does not affect another user\'s ownership view', function () {
    $mine = ownCard('Pikachu');
    $theirs = ownCard('Charizard');
    $other = User::factory()->create();

    addToCollection($this->user, $mine);
    addToCollection($other, $theirs);

    $res = app(SearchCatalog::class)(['owned' => 'owned', 'user_id' => $this->user->id]);

    expect(ids($res))->toBe([$mine->id]); // not $theirs
});

test('a card with no base_key still shows as unowned', function () {
    // base_key is nullable, and the ownership filter matches on it. Because
    // "NULL IN (...)" is NULL rather than false, a NULL slipping into the
    // negated branch would silently drop the row from "unowned" entirely —
    // the card would appear nowhere. Guarded, so pin it.
    $owned = ownCard('Pikachu');
    $keyless = ownCard('Charizard');
    $keyless->forceFill(['base_key' => null])->save();

    addToCollection($this->user, $owned);

    $unowned = app(SearchCatalog::class)(['owned' => 'unowned', 'user_id' => $this->user->id]);

    expect(ids($unowned))->toContain($keyless->id)
        ->and(ids($unowned))->not->toContain($owned->id);
});

test('a user who owns nothing sees everything as unowned and nothing as owned', function () {
    $a = ownCard('Pikachu');
    $b = ownCard('Charizard');
    $empty = User::factory()->create();

    expect(ids(app(SearchCatalog::class)(['owned' => 'unowned', 'user_id' => $empty->id])))
        ->toBe([$a->id, $b->id])
        ->and(ids(app(SearchCatalog::class)(['owned' => 'owned', 'user_id' => $empty->id])))
        ->toBe([]);
});
