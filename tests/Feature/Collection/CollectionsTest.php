<?php

use App\Enums\MembershipTier;
use App\Models\CatalogItem;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'membership_tier' => MembershipTier::Collector, // limit 5
    ]);
});

test('a user can create a named collection within their tier limit', function () {
    $this->user->defaultCollection();

    $this->actingAs($this->user)->post('/collections', ['name' => 'Vintage'])->assertRedirect();

    expect(Collection::where('user_id', $this->user->id)->where('slug', 'vintage')->exists())->toBeTrue();
});

test('the collection limit is enforced', function () {
    config(['membership.limits.collections.collector' => 1]);
    $this->user->defaultCollection(); // already at the limit of 1

    $this->actingAs($this->user)->from('/collection')
        ->post('/collections', ['name' => 'Extra'])
        ->assertSessionHasErrors('name');

    expect($this->user->collections()->count())->toBe(1);
});

test('renaming and sharing a collection works', function () {
    $col = Collection::factory()->for($this->user)->create(['name' => 'Old', 'slug' => 'old']);

    $this->actingAs($this->user)
        ->patch("/collections/{$col->id}", ['name' => 'New Name', 'is_public' => true])
        ->assertRedirect();

    $col->refresh();
    expect($col->name)->toBe('New Name')
        ->and($col->slug)->toBe('new-name')
        ->and($col->is_public)->toBeTrue();
});

test('toggling the default collection public syncs the user-level flag', function () {
    $default = $this->user->defaultCollection();
    expect($this->user->fresh()->is_collection_public)->toBeFalse();

    $this->actingAs($this->user)
        ->patch("/collections/{$default->id}", ['is_public' => true])
        ->assertRedirect();

    expect($default->fresh()->is_public)->toBeTrue()
        ->and($this->user->fresh()->is_collection_public)->toBeTrue();
});

test('toggling a non-default collection public leaves the user-level flag alone', function () {
    $this->user->defaultCollection();
    $col = Collection::factory()->for($this->user)->create(['is_public' => false]);

    $this->actingAs($this->user)
        ->patch("/collections/{$col->id}", ['is_public' => true])
        ->assertRedirect();

    expect($col->fresh()->is_public)->toBeTrue()
        ->and($this->user->fresh()->is_collection_public)->toBeFalse();
});

test('deleting a collection moves its cards to the default', function () {
    $default = $this->user->defaultCollection();
    $col = Collection::factory()->for($this->user)->create();
    $item = CatalogItem::factory()->create();
    $ci = CollectionItem::factory()->for($this->user)->create([
        'collection_id' => $col->id, 'catalog_item_id' => $item->id,
    ]);

    $this->actingAs($this->user)->delete("/collections/{$col->id}")->assertRedirect();

    expect(Collection::find($col->id))->toBeNull()
        ->and($ci->fresh()->collection_id)->toBe($default->id);
});

test('the default collection cannot be deleted', function () {
    $default = $this->user->defaultCollection();

    $this->actingAs($this->user)->from('/collection')
        ->delete("/collections/{$default->id}")
        ->assertSessionHasErrors('collection');

    expect(Collection::find($default->id))->not->toBeNull();
});

test('a holding can be moved to another collection', function () {
    $default = $this->user->defaultCollection();
    $other = Collection::factory()->for($this->user)->create();
    $item = CatalogItem::factory()->create();
    $ci = CollectionItem::factory()->for($this->user)->create([
        'collection_id' => $default->id, 'catalog_item_id' => $item->id,
    ]);

    $this->actingAs($this->user)
        ->patch("/collection/{$ci->id}", ['collection_id' => $other->id])
        ->assertRedirect();

    expect($ci->fresh()->collection_id)->toBe($other->id);
});

test('a public named collection is viewable; a private one 404s', function () {
    $this->user->forceFill(['username' => 'ash'])->save();
    Collection::factory()->for($this->user)->create(['name' => 'Slabs', 'slug' => 'slabs', 'is_public' => true]);
    Collection::factory()->for($this->user)->create(['name' => 'Secret', 'slug' => 'secret', 'is_public' => false]);

    $this->get('/collection/ash/slabs')->assertOk();
    $this->get('/collection/ash/secret')->assertNotFound();
});

test('a user cannot manage another user\'s collection', function () {
    $other = User::factory()->create();
    $col = Collection::factory()->for($other)->create();

    $this->actingAs($this->user)->patch("/collections/{$col->id}", ['name' => 'Hijack'])->assertForbidden();
});
