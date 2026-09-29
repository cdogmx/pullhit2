<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\SaleObservation;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use App\Models\WishlistItem;

/**
 * Reviewing what a scan created.
 *
 * The rejection tests are the important ones. catalog_items cascades to
 * collection_items, so deleting a provisional row somebody owns would strip the
 * card from their collection with no warning and no way back — the same hazard
 * the rehash command guards against with "rows a real person created are merged,
 * never dropped".
 */
beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true]);
    $vertical = Vertical::firstOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);

    $this->line = ProductLine::factory()->create([
        'vertical_id' => $vertical->id, 'slug' => 'star-wars-unlimited',
        'name' => 'Star Wars Unlimited', 'is_provisional' => true,
    ]);
    $this->set = Set::factory()->create([
        'product_line_id' => $this->line->id, 'name' => 'Shadows of the Galaxy', 'is_provisional' => true,
    ]);
    $this->card = CatalogItem::factory()->create([
        'vertical_id' => $vertical->id, 'product_line_id' => $this->line->id, 'set_id' => $this->set->id,
        'name' => 'Ahsoka Tano', 'number' => '042',
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
        'is_provisional' => true, 'provisional_scans' => 7,
    ]);
});

test('the queue leads with the most-scanned card', function () {
    CatalogItem::factory()->create([
        'set_id' => $this->set->id, 'product_line_id' => $this->line->id,
        'name' => 'Barely Scanned', 'is_provisional' => true, 'provisional_scans' => 1,
    ]);

    $this->actingAs($this->admin)->get('/admin/provisional-cards')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/provisional-cards')
            // Demand order: what people are actually holding, not what was
            // scanned most recently.
            ->where('cards.data.0.name', 'Ahsoka Tano')
            ->where('cards.data.0.scans', 7)
            ->where('cards.data.0.new_brand', true));
});

test('confirming brings the set and brand with it', function () {
    $this->actingAs($this->admin)
        ->post("/admin/provisional-cards/{$this->card->id}/confirm")
        ->assertRedirect();

    // A confirmed card whose set is still quarantined has a URL nobody can reach.
    expect($this->card->fresh()->is_provisional)->toBeFalse()
        ->and($this->set->fresh()->is_provisional)->toBeFalse()
        ->and($this->line->fresh()->is_provisional)->toBeFalse();
});

test('rejecting an unheld card removes it and its empty parents', function () {
    $this->actingAs($this->admin)
        ->delete("/admin/provisional-cards/{$this->card->id}")
        ->assertRedirect();

    expect(CatalogItem::find($this->card->id))->toBeNull()
        // The set and brand existed only for this card.
        ->and(Set::find($this->set->id))->toBeNull()
        ->and(ProductLine::find($this->line->id))->toBeNull();
});

test('rejecting refuses while somebody holds the card', function () {
    $owner = User::factory()->create();
    $collection = $owner->collections()->create(['name' => 'Main', 'slug' => 'main-'.$owner->id]);
    $collection->items()->create([
        'user_id' => $owner->id, 'catalog_item_id' => $this->card->id,
        'quantity' => 1, 'condition' => 'NM',
    ]);

    $this->actingAs($this->admin)
        ->delete("/admin/provisional-cards/{$this->card->id}")
        ->assertSessionHasErrors('reject');

    // The row survives, and so does their collection entry.
    expect(CatalogItem::find($this->card->id))->not->toBeNull()
        ->and($collection->items()->count())->toBe(1);
});

test('merging moves holdings and wants to the right card', function () {
    $real = CatalogItem::factory()->create(['name' => 'Ahsoka Tano', 'number' => '42']);

    $owner = User::factory()->create();
    $collection = $owner->collections()->create(['name' => 'Main', 'slug' => 'main-'.$owner->id]);
    $collection->items()->create([
        'user_id' => $owner->id, 'catalog_item_id' => $this->card->id,
        'quantity' => 2, 'condition' => 'NM',
    ]);
    $wanter = User::factory()->create();
    WishlistItem::create([
        'user_id' => $wanter->id,
        'wishlist_id' => $wanter->wishlists()->create(['name' => 'W', 'slug' => 'w-'.$wanter->id])->id,
        'catalog_item_id' => $this->card->id,
    ]);

    $this->actingAs($this->admin)
        ->post("/admin/provisional-cards/{$this->card->id}/merge", ['into' => $real->id])
        ->assertRedirect();

    expect(CatalogItem::find($this->card->id))->toBeNull()
        ->and($collection->items()->where('catalog_item_id', $real->id)->first()?->quantity)->toBe(2)
        ->and(WishlistItem::where('catalog_item_id', $real->id)->exists())->toBeTrue();
});

test('a merge adds quantities rather than letting one row win', function () {
    // Either row may be the one the person actually edited, so neither is
    // discarded.
    $real = CatalogItem::factory()->create(['name' => 'Ahsoka Tano', 'number' => '42']);
    $owner = User::factory()->create();
    $collection = $owner->collections()->create(['name' => 'Main', 'slug' => 'main-'.$owner->id]);

    $collection->items()->create(['user_id' => $owner->id, 'catalog_item_id' => $this->card->id, 'quantity' => 2, 'condition' => 'NM']);
    $collection->items()->create(['user_id' => $owner->id, 'catalog_item_id' => $real->id, 'quantity' => 3, 'condition' => 'NM']);

    $this->actingAs($this->admin)
        ->post("/admin/provisional-cards/{$this->card->id}/merge", ['into' => $real->id])
        ->assertRedirect();

    expect($collection->items()->where('catalog_item_id', $real->id)->sum('quantity'))->toBe(5)
        ->and($collection->items()->count())->toBe(1);
});

test('a merge does not carry the bad comps onto the good card', function () {
    // The comps were gathered against a number that may have been misread, which
    // is the mistake being undone. Moving them would move the wrong price too.
    $real = CatalogItem::factory()->create(['name' => 'Ahsoka Tano', 'number' => '42']);

    SaleObservation::factory()->for($this->card)->create([
        'price' => 99000, 'is_synthetic' => false, 'condition' => 'NM',
        'raw' => ['title' => 'something misread', 'source' => 'ebay'],
    ]);
    MarketValue::factory()->for($this->card)->create(['state_key' => 'NM', 'median' => 99000]);

    $this->actingAs($this->admin)
        ->post("/admin/provisional-cards/{$this->card->id}/merge", ['into' => $real->id])
        ->assertRedirect();

    expect(SaleObservation::where('catalog_item_id', $real->id)->count())->toBe(0)
        ->and(MarketValue::where('catalog_item_id', $real->id)->count())->toBe(0);
});

test('only admins can review', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/provisional-cards')
        ->assertForbidden();
});

test('a reviewer can correct the read before confirming it', function () {
    // The two Topps scans produced brands called "Topps Chrome Pixar" and
    // "Disney Topps Chrome" for one maker and two sets. That is an edit, not a
    // rejection — and "accept or reject" alone would have thrown both away.
    $this->actingAs($this->admin)
        ->patch("/admin/provisional-cards/{$this->card->id}", [
            'name' => 'Ahsoka Tano (Leader)',
            'number' => '42',
            'brand_name' => 'Topps',
            'set_name' => 'Chrome Disney 2026',
            'attributes' => ['language' => 'en', 'variant' => 'holo'],
        ])
        ->assertRedirect();

    $card = $this->card->fresh();

    expect($card->name)->toBe('Ahsoka Tano (Leader)')
        ->and($card->number)->toBe('42')
        ->and($card->set->fresh()->name)->toBe('Chrome Disney 2026')
        ->and($card->productLine->fresh()->name)->toBe('Topps')
        // Still provisional: editing is not confirming.
        ->and($card->is_provisional)->toBeTrue();
});

test('an edit rehashes the row', function () {
    // identity_hash is a function of the name, the number and the facets. An
    // edit that does not rehash leaves the row hashed as the card it used to be,
    // and the next import inserts a duplicate rather than matching it.
    $before = $this->card->identity_hash;

    $this->actingAs($this->admin)
        ->patch("/admin/provisional-cards/{$this->card->id}", [
            'name' => 'A Completely Different Name',
            'attributes' => ['language' => 'en', 'variant' => 'holo'],
        ])
        ->assertRedirect();

    expect($this->card->fresh()->identity_hash)->not->toBe($before);
});

test('an edit cannot give a card a facet its vertical does not have', function () {
    $this->actingAs($this->admin)
        ->patch("/admin/provisional-cards/{$this->card->id}", [
            'name' => 'Ahsoka Tano',
            // "parallel" belongs to collectibles; this card is a game single.
            'attributes' => ['language' => 'en', 'variant' => 'holo', 'parallel' => 'Gold'],
        ])
        ->assertSessionHasErrors();

    expect($this->card->fresh()->name)->toBe('Ahsoka Tano');
});

test('a confirmed card is not editable from this queue', function () {
    $this->card->forceFill(['is_provisional' => false])->save();

    $this->actingAs($this->admin)
        ->patch("/admin/provisional-cards/{$this->card->id}", ['name' => 'Nope'])
        ->assertNotFound();
});
