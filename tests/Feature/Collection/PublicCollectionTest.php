<?php

use App\Actions\Collection\AddToCollection;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a public collection is viewable by anyone, without private financials', function () {
    $user = User::factory()->create(['name' => 'Ash', 'email_verified_at' => now()]);
    $user->forceFill(['username' => 'ash', 'is_collection_public' => true, 'is_wishlist_public' => false])->save();

    $item = CatalogItem::factory()->create([
        'name' => 'Pikachu',
        'attributes' => ['language' => 'en', 'rarity' => 'Common', 'variant' => 'holo'],
    ]);
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => Condition::NearMint, 'median' => 5000,
    ]);
    app(AddToCollection::class)($user, $item, ['condition' => 'NM', 'quantity' => 2, 'unit_cost' => 1000]);

    $this->get('/collection/ash')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('collection/public')
            ->where('owner.username', 'ash')
            ->missing('owner.name') // real name never exposed publicly
            ->where('summary.card_count', 2)
            ->where('holdings.0.name', 'Pikachu')
            ->where('holdings.0.market_value', 10000) // 2 × 5000
            ->missing('holdings.0.cost_basis')        // cost basis stays private
            ->missing('summary.total_cost'),
        );
});

test('a private collection 404s', function () {
    $user = User::factory()->create();
    $user->forceFill(['username' => 'secret', 'is_collection_public' => false, 'is_wishlist_public' => false])->save();

    $this->get('/collection/secret')->assertNotFound();
});

test('an unknown username 404s', function () {
    $this->get('/collection/nobody-here')->assertNotFound();
});

test('the export route still wins over a username handle', function () {
    // /collection/export must reach the (auth) export route, not the public page.
    $this->get('/collection/export')->assertRedirect('/login');
});

test('a user can set a username and make their collection public', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->patch('/settings/collection', ['username' => 'ash-ketchum', 'is_collection_public' => true, 'is_wishlist_public' => false])
        ->assertRedirect();

    $user->refresh();
    expect($user->username)->toBe('ash-ketchum')
        ->and($user->is_collection_public)->toBeTrue();
});

test('going public requires a username', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->patch('/settings/collection', ['username' => '', 'is_collection_public' => true, 'is_wishlist_public' => false])
        ->assertSessionHasErrors('username');

    expect($user->fresh()->is_collection_public)->toBeFalse();
});

test('an existing username cannot be changed', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->forceFill(['username' => 'original'])->save();

    $this->actingAs($user)
        ->patch('/settings/collection', [
            'username' => 'something-else',
            'is_collection_public' => true,
            'is_wishlist_public' => false,
        ])
        ->assertRedirect();

    $user->refresh();
    // Username is permanent; only the privacy toggle changes.
    expect($user->username)->toBe('original')
        ->and($user->is_collection_public)->toBeTrue();
});

test('usernames must be unique and not reserved', function () {
    User::factory()->create()->forceFill(['username' => 'taken'])->save();
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->patch('/settings/collection', ['username' => 'taken', 'is_collection_public' => false, 'is_wishlist_public' => false])
        ->assertSessionHasErrors('username');

    $this->actingAs($user)
        ->patch('/settings/collection', ['username' => 'export', 'is_collection_public' => false, 'is_wishlist_public' => false])
        ->assertSessionHasErrors('username');
});
