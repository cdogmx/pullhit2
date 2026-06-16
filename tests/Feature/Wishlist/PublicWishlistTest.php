<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\User;
use App\Models\WishlistItem;
use Inertia\Testing\AssertableInertia as Assert;

test('a public wishlist is viewable by anyone, without target prices', function () {
    $user = User::factory()->create(['name' => 'Ash', 'email_verified_at' => now()]);
    $user->forceFill(['username' => 'ash', 'is_wishlist_public' => true])->save();

    $item = CatalogItem::factory()->create([
        'name' => 'Pikachu',
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'holo'],
    ]);
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'median' => 5000,
    ]);
    WishlistItem::create(['user_id' => $user->id, 'wishlist_id' => $user->defaultWishlist()->id, 'catalog_item_id' => $item->id, 'target_price' => 4000]);

    $this->get('/wishlist/ash')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('wishlist/public')
            ->where('owner.username', 'ash')
            ->missing('owner.name') // real name never exposed publicly
            ->where('items.0.name', 'Pikachu')
            ->where('items.0.current_value', 5000)
            ->missing('items.0.target_price'), // target stays private
        );
});

test('a private wishlist 404s', function () {
    $user = User::factory()->create();
    $user->forceFill(['username' => 'secret', 'is_wishlist_public' => false])->save();

    $this->get('/wishlist/secret')->assertNotFound();
});

test('the wishlist page exposes the share URL only when public', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['username' => 'ash', 'is_wishlist_public' => true])->save();

    $this->actingAs($user)->get('/wishlist')
        ->assertInertia(fn (Assert $page) => $page->where('publicUrl', url('/wishlist/ash')));
});
