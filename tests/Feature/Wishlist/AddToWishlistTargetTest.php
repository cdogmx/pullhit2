<?php

use App\Models\CatalogItem;
use App\Models\User;

function wisher(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'username' => 'wisher'.fake()->unique()->numberBetween(1, 999999),
        'email_verified_at' => now(),
        'membership_tier' => 'guru',
    ], $attrs));
}

test('a card can be added to a chosen existing wishlist', function () {
    $user = wisher();
    $item = CatalogItem::factory()->create();
    $second = $user->wishlists()->create(['name' => 'Grails', 'slug' => 'grails', 'sort' => 1]);

    $this->actingAs($user)->post('/wishlist', [
        'catalog_item_id' => $item->id,
        'wishlist_id' => $second->id,
    ])->assertRedirect();

    expect($user->wishlistItems()
        ->where('wishlist_id', $second->id)
        ->where('catalog_item_id', $item->id)->exists())->toBeTrue();
});

test('adding with a new wishlist name creates it and adds there', function () {
    $user = wisher();
    $item = CatalogItem::factory()->create();

    $this->actingAs($user)->post('/wishlist', [
        'catalog_item_id' => $item->id,
        'new_wishlist_name' => 'Birthday',
    ])->assertRedirect();

    $created = $user->wishlists()->where('name', 'Birthday')->first();
    expect($created)->not->toBeNull()
        ->and($user->wishlistItems()->where('wishlist_id', $created->id)->exists())->toBeTrue();
});

test('creating a new wishlist beyond the tier limit is rejected', function () {
    $user = wisher(['membership_tier' => 'free']); // free allows 1 wishlist
    $item = CatalogItem::factory()->create();
    $user->defaultWishlist(); // occupies the single slot

    $this->actingAs($user)->post('/wishlist', [
        'catalog_item_id' => $item->id,
        'new_wishlist_name' => 'Too many',
    ])->assertSessionHasErrors('name');

    expect($user->wishlists()->where('name', 'Too many')->exists())->toBeFalse();
});

test('the wishlist targets endpoint lists wishlists and create allowance', function () {
    $user = wisher();
    $user->defaultWishlist();

    $this->actingAs($user)->getJson('/wishlist/targets')
        ->assertOk()
        ->assertJsonStructure(['targets', 'can_create', 'limit'])
        ->assertJsonPath('can_create', true);
});
