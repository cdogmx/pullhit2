<?php

use App\Actions\Valuation\ResolveRaceSources;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\PriceRace;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'collector']);
    $this->line = ProductLine::factory()->create(['slug' => 'pokemon']);
    $this->set = Set::factory()->for($this->line)->create(['slug' => 'base', 'language' => 'en']);

    $this->card = fn (string $name) => CatalogItem::factory()->create([
        'product_line_id' => $this->line->id, 'set_id' => $this->set->id,
        'name' => $name, 'item_type' => ItemType::Single,
        'attributes' => ['language' => 'en'],
    ]);
});

test('a collection races what is in it', function () {
    $collection = Collection::factory()->create(['user_id' => $this->user->id, 'name' => 'Binder']);
    $owned = ($this->card)('Charizard');
    $elsewhere = ($this->card)('Not mine');

    CollectionItem::factory()->create([
        'user_id' => $this->user->id, 'collection_id' => $collection->id,
        'catalog_item_id' => $owned->id,
    ]);

    $ids = app(ResolveRaceSources::class)([['type' => 'collection', 'id' => $collection->id]])['ids'];

    expect($ids)->toBe([$owned->id])->not->toContain($elsewhere->id);
});

test('a wishlist races what is on it', function () {
    $wishlist = Wishlist::factory()->create(['user_id' => $this->user->id, 'name' => 'Chase']);
    $wanted = ($this->card)('Umbreon');

    WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $wishlist->id,
        'catalog_item_id' => $wanted->id,
    ]);

    expect(app(ResolveRaceSources::class)([['type' => 'wishlist', 'id' => $wishlist->id]])['ids'])
        ->toBe([$wanted->id]);
});

test('a list source stays a reference, so the race follows the list', function () {
    // Snapshotting the ids would freeze a race of what someone owns on the day
    // they made it.
    $collection = Collection::factory()->create(['user_id' => $this->user->id]);
    $first = ($this->card)('First');
    CollectionItem::factory()->create([
        'user_id' => $this->user->id, 'collection_id' => $collection->id, 'catalog_item_id' => $first->id,
    ]);

    $race = PriceRace::create([
        'user_id' => $this->user->id, 'name' => 'Mine',
        'sources' => [['type' => 'collection', 'id' => $collection->id]],
    ]);

    $bought = ($this->card)('Bought later');
    CollectionItem::factory()->create([
        'user_id' => $this->user->id, 'collection_id' => $collection->id, 'catalog_item_id' => $bought->id,
    ]);

    expect(app(ResolveRaceSources::class)($race->sources)['ids'])->toContain($bought->id);
});

test('racing from a collection starts private', function () {
    // A race is shared by URL. A collection is not public just because its
    // owner wanted to watch it move.
    $collection = Collection::factory()->create(['user_id' => $this->user->id, 'name' => 'Binder']);

    $this->actingAs($this->user)->get("/races/new?collection={$collection->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('prefill.name', 'Binder')
            ->where('prefill.is_public', false)
            ->where('prefill.sources.0.type', 'collection')
            ->where('prefill.sources.0.id', $collection->id));
});

test('somebody else\'s list is not yours to race', function () {
    // The id is a guessable integer; without the check a race is a way to read
    // what anyone owns.
    $theirs = Collection::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)->get("/races/new?collection={$theirs->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('prefill', null));

    $this->actingAs($this->user)->post('/races', [
        'name' => 'Theirs', 'sources' => [['type' => 'collection', 'id' => $theirs->id]],
    ])->assertForbidden();

    expect(PriceRace::count())->toBe(0);
});
