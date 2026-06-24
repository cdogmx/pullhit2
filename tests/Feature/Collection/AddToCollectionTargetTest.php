<?php

use App\Models\CatalogItem;
use App\Models\User;

function buyer(array $attrs = []): User
{
    // A username + verified email so the social-login RequireUsername /
    // verified middleware don't redirect.
    return User::factory()->create(array_merge([
        'username' => 'buyer'.fake()->unique()->numberBetween(1, 999999),
        'email_verified_at' => now(),
        'membership_tier' => 'guru',
    ], $attrs));
}

test('a card can be added to a chosen existing collection', function () {
    $user = buyer();
    $item = CatalogItem::factory()->create();
    $second = $user->collections()->create(['name' => 'Graded', 'slug' => 'graded', 'sort' => 1]);

    $this->actingAs($user)->post('/collection', [
        'catalog_item_id' => $item->id,
        'collection_id' => $second->id,
        'condition' => 'NM',
        'quantity' => 1,
        'unit_cost' => 0,
    ])->assertRedirect();

    expect($user->collectionItems()
        ->where('collection_id', $second->id)
        ->where('catalog_item_id', $item->id)->exists())->toBeTrue();
});

test('adding with a new collection name creates it and adds there', function () {
    $user = buyer();
    $item = CatalogItem::factory()->create();

    $this->actingAs($user)->post('/collection', [
        'catalog_item_id' => $item->id,
        'new_collection_name' => 'Slabs',
        'condition' => 'NM',
        'quantity' => 1,
        'unit_cost' => 0,
    ])->assertRedirect();

    $created = $user->collections()->where('name', 'Slabs')->first();
    expect($created)->not->toBeNull()
        ->and($user->collectionItems()->where('collection_id', $created->id)->exists())->toBeTrue();
});

test('creating a new collection beyond the tier limit is rejected', function () {
    $user = buyer(['membership_tier' => 'free']); // free allows 1 collection
    $item = CatalogItem::factory()->create();
    $user->defaultCollection(); // occupies the single slot

    $this->actingAs($user)->post('/collection', [
        'catalog_item_id' => $item->id,
        'new_collection_name' => 'Too many',
        'condition' => 'NM',
        'quantity' => 1,
        'unit_cost' => 0,
    ])->assertSessionHasErrors('name');

    expect($user->collections()->where('name', 'Too many')->exists())->toBeFalse();
});

test('the targets endpoint lists the user collections and create allowance', function () {
    $user = buyer();
    $user->defaultCollection();

    $this->actingAs($user)->getJson('/collection/targets')
        ->assertOk()
        ->assertJsonStructure(['targets', 'can_create', 'limit'])
        ->assertJsonPath('can_create', true);
});
