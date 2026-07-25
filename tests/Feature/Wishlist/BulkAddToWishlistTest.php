<?php

use App\Models\CatalogItem;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'membership_tier' => 'guru',
    ]);
    $this->cards = CatalogItem::factory()->count(3)->create();
});

test('it adds every selected card to the default wishlist', function () {
    $this->actingAs($this->user)
        ->post('/wishlist/bulk', ['catalog_item_ids' => $this->cards->pluck('id')->all()])
        ->assertRedirect()
        ->assertSessionHas('success', 'Added 3 cards to your wishlist.');

    expect($this->user->wishlistItems()->count())->toBe(3)
        ->and($this->user->wishlistItems()->pluck('wishlist_id')->unique()->all())
        ->toBe([$this->user->defaultWishlist()->id]);
});

test('adding the same batch twice is idempotent', function () {
    $payload = ['catalog_item_ids' => $this->cards->pluck('id')->all()];

    $this->actingAs($this->user)->post('/wishlist/bulk', $payload);
    $this->actingAs($this->user)->post('/wishlist/bulk', $payload)->assertRedirect();

    // The (wishlist, card) unique index would blow up on a raw insert.
    expect($this->user->wishlistItems()->count())->toBe(3);
});

test('it can add the batch to a new wishlist', function () {
    $this->actingAs($this->user)
        ->post('/wishlist/bulk', [
            'catalog_item_ids' => $this->cards->pluck('id')->all(),
            'new_wishlist_name' => 'Grails',
        ])
        ->assertRedirect();

    $list = $this->user->wishlists()->where('name', 'Grails')->firstOrFail();

    expect($this->user->wishlistItems()->where('wishlist_id', $list->id)->count())->toBe(3);
});

test('unknown ids are skipped rather than failing the batch', function () {
    $this->actingAs($this->user)
        ->post('/wishlist/bulk', ['catalog_item_ids' => [$this->cards[0]->id, 999_999]])
        ->assertRedirect()
        ->assertSessionHas('success', 'Added 1 card to your wishlist.');

    expect($this->user->wishlistItems()->count())->toBe(1);
});

test('it cannot target another user wishlist', function () {
    $theirs = User::factory()->create()->defaultWishlist();

    $this->actingAs($this->user)
        ->post('/wishlist/bulk', [
            'catalog_item_ids' => [$this->cards[0]->id],
            'wishlist_id' => $theirs->id,
        ])
        ->assertSessionHasErrors('wishlist_id');

    expect($this->user->wishlistItems()->count())->toBe(0);
});

test('bulk add requires authentication', function () {
    $this->post('/wishlist/bulk', ['catalog_item_ids' => [$this->cards[0]->id]])
        ->assertRedirect('/login');
});
