<?php

use App\Enums\MembershipTier;
use App\Models\CatalogItem;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'membership_tier' => MembershipTier::Collector, // wishlist limit 5
    ]);
});

test('a user can create a named wishlist within their tier limit', function () {
    $this->user->defaultWishlist();

    $this->actingAs($this->user)->post('/wishlists', ['name' => 'Grails'])->assertRedirect();

    expect(Wishlist::where('user_id', $this->user->id)->where('slug', 'grails')->exists())->toBeTrue();
});

test('the wishlist limit is enforced', function () {
    config(['membership.limits.wishlists.collector' => 1]);
    $this->user->defaultWishlist();

    $this->actingAs($this->user)->from('/wishlist')
        ->post('/wishlists', ['name' => 'Extra'])
        ->assertSessionHasErrors('name');

    expect($this->user->wishlists()->count())->toBe(1);
});

test('renaming and sharing a wishlist works', function () {
    $wl = Wishlist::factory()->for($this->user)->create(['name' => 'Old', 'slug' => 'old']);

    $this->actingAs($this->user)
        ->patch("/wishlists/{$wl->id}", ['name' => 'New Name', 'is_public' => true])
        ->assertRedirect();

    $wl->refresh();
    expect($wl->name)->toBe('New Name')
        ->and($wl->slug)->toBe('new-name')
        ->and($wl->is_public)->toBeTrue();
});

test('deleting a wishlist moves its cards to the default', function () {
    $default = $this->user->defaultWishlist();
    $wl = Wishlist::factory()->for($this->user)->create();
    $item = CatalogItem::factory()->create();
    $wi = WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $wl->id, 'catalog_item_id' => $item->id,
    ]);

    $this->actingAs($this->user)->delete("/wishlists/{$wl->id}")->assertRedirect();

    expect(Wishlist::find($wl->id))->toBeNull()
        ->and($wi->fresh()->wishlist_id)->toBe($default->id);
});

test('the default wishlist cannot be deleted', function () {
    $default = $this->user->defaultWishlist();

    $this->actingAs($this->user)->from('/wishlist')
        ->delete("/wishlists/{$default->id}")
        ->assertSessionHasErrors('wishlist');

    expect(Wishlist::find($default->id))->not->toBeNull();
});

test('a wishlist item can be moved to another wishlist', function () {
    $default = $this->user->defaultWishlist();
    $other = Wishlist::factory()->for($this->user)->create();
    $item = CatalogItem::factory()->create();
    $wi = WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $default->id, 'catalog_item_id' => $item->id,
    ]);

    $this->actingAs($this->user)
        ->patch("/wishlist/{$wi->id}", ['wishlist_id' => $other->id])
        ->assertRedirect();

    expect($wi->fresh()->wishlist_id)->toBe($other->id);
});

test('a public named wishlist is viewable; a private one 404s', function () {
    $this->user->forceFill(['username' => 'ash'])->save();
    Wishlist::factory()->for($this->user)->create(['name' => 'Grails', 'slug' => 'grails', 'is_public' => true]);
    Wishlist::factory()->for($this->user)->create(['name' => 'Secret', 'slug' => 'secret', 'is_public' => false]);

    $this->get('/wishlist/ash/grails')->assertOk();
    $this->get('/wishlist/ash/secret')->assertNotFound();
});
