<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\User;
use App\Models\WishlistItem;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->item = CatalogItem::factory()->create(['name' => 'Charizard']);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'median' => 10000,
    ]);
});

test('a user can add a card to their wishlist (idempotent) and remove it', function () {
    $this->actingAs($this->user)->post('/wishlist', ['catalog_item_id' => $this->item->id])->assertRedirect();
    $this->actingAs($this->user)->post('/wishlist', ['catalog_item_id' => $this->item->id])->assertRedirect();

    expect(WishlistItem::count())->toBe(1)
        ->and(WishlistItem::where('user_id', $this->user->id)->where('catalog_item_id', $this->item->id)->exists())->toBeTrue();

    // Heart toggle-off clears every list that has the card.
    $this->actingAs($this->user)->delete("/wishlist/{$this->item->id}")->assertRedirect();
    expect(WishlistItem::count())->toBe(0);
});

test('removing without wishlist_id clears the card from every wishlist', function () {
    $default = $this->user->defaultWishlist();
    $other = $this->user->wishlists()->create(['name' => 'Grails', 'slug' => 'grails', 'sort' => 1]);

    WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $default->id, 'catalog_item_id' => $this->item->id,
    ]);
    WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $other->id, 'catalog_item_id' => $this->item->id,
    ]);

    $this->actingAs($this->user)->delete("/wishlist/{$this->item->id}")->assertRedirect();

    expect(WishlistItem::where('user_id', $this->user->id)->where('catalog_item_id', $this->item->id)->count())->toBe(0);
});

test('removing with wishlist_id only clears that list', function () {
    $default = $this->user->defaultWishlist();
    $other = $this->user->wishlists()->create(['name' => 'Grails', 'slug' => 'grails', 'sort' => 1]);

    WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $default->id, 'catalog_item_id' => $this->item->id,
    ]);
    WishlistItem::create([
        'user_id' => $this->user->id, 'wishlist_id' => $other->id, 'catalog_item_id' => $this->item->id,
    ]);

    $this->actingAs($this->user)
        ->delete("/wishlist/{$this->item->id}", ['wishlist_id' => $other->id])
        ->assertRedirect();

    expect(
        WishlistItem::where('user_id', $this->user->id)
            ->where('catalog_item_id', $this->item->id)
            ->pluck('wishlist_id')
            ->all(),
    )->toBe([$default->id]);
});

test('the wishlist page shows current value + at-target flag', function () {
    // Target ($120) above current value ($100) → "at or below target".
    WishlistItem::create(['user_id' => $this->user->id, 'wishlist_id' => $this->user->defaultWishlist()->id, 'catalog_item_id' => $this->item->id, 'target_price' => 12000]);

    $this->actingAs($this->user)->get('/wishlist')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('wishlist/index')
            ->where('summary.item_count', 1)
            ->where('summary.below_target', 1)
            ->where('items.0.current_value', 10000)
            ->where('items.0.below_target', true)
            ->where('items.0.catalog_item.name', 'Charizard'),
        );
});

test('a user can set a target price', function () {
    $wish = WishlistItem::create(['user_id' => $this->user->id, 'wishlist_id' => $this->user->defaultWishlist()->id, 'catalog_item_id' => $this->item->id]);

    $this->actingAs($this->user)->patch("/wishlist/{$wish->id}", ['target_price' => 5000])->assertRedirect();

    expect($wish->fresh()->target_price)->toBe(5000);
});

test("a user cannot edit another user's wishlist item", function () {
    $other = User::factory()->create(['email_verified_at' => now()]);
    $wish = WishlistItem::create(['user_id' => $other->id, 'wishlist_id' => $other->defaultWishlist()->id, 'catalog_item_id' => $this->item->id]);

    $this->actingAs($this->user)->patch("/wishlist/{$wish->id}", ['target_price' => 5000])->assertForbidden();
});

test('the wishlist requires authentication', function () {
    $this->post('/wishlist', ['catalog_item_id' => $this->item->id])->assertRedirect('/login');
});
