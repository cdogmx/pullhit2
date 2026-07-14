<?php

use App\Actions\Collection\AddToCollection;
use App\Models\CatalogItem;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function folderUser(): User
{
    return User::factory()->create([
        'username' => 'ash'.fake()->unique()->numberBetween(1, 999999),
        'email_verified_at' => now(),
    ]);
}

function addToFolder(User $user, string $folder, string $name = 'Pikachu'): void
{
    $item = CatalogItem::factory()->create(['name' => $name, 'attributes' => ['language' => 'en']]);
    app(AddToCollection::class)($user, $item, ['condition' => 'NM', 'quantity' => 1, 'folder' => $folder]);
}

test('the collection index lists folders from the holdings', function () {
    $user = folderUser();
    addToFolder($user, 'Graded');

    $this->actingAs($user)->get('/collection')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->where('folders.0.name', 'Graded')
            ->where('folders.0.is_public', false)
            ->where('folders.0.items_count', 1));
});

test('an owner can toggle a folder public', function () {
    $user = folderUser();
    addToFolder($user, 'Graded');
    $folder = $user->defaultCollection()->ensureFolder('Graded');

    $this->actingAs($user)
        ->patch("/collection/folders/{$folder->id}", ['is_public' => true])
        ->assertRedirect();

    expect($folder->fresh()->is_public)->toBeTrue();
});

test('a public folder is viewable even when the collection is private, showing only its items', function () {
    $user = folderUser(); // collection private by default
    addToFolder($user, 'Graded', 'Charizard');
    addToFolder($user, 'Raw', 'Squirtle');

    $collection = $user->defaultCollection();
    $folder = $collection->ensureFolder('Graded');
    $folder->update(['is_public' => true]);

    $this->get("/collection/{$user->username}/{$collection->slug}/folder/{$folder->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('collection/public')
            ->where('folder.name', 'Graded')
            ->where('meta.title', "{$user->username}'s Graded folder")
            ->has('holdings', 1)); // only the Graded folder's single item
});

test('a private folder 404s', function () {
    $user = folderUser();
    addToFolder($user, 'Secret');
    $collection = $user->defaultCollection();
    $folder = $collection->ensureFolder('Secret');

    $this->get("/collection/{$user->username}/{$collection->slug}/folder/{$folder->slug}")
        ->assertNotFound();
});

test('a non-owner cannot toggle a folder', function () {
    $owner = folderUser();
    addToFolder($owner, 'Graded');
    $folder = $owner->defaultCollection()->ensureFolder('Graded');

    $this->actingAs(folderUser())
        ->patch("/collection/folders/{$folder->id}", ['is_public' => true])
        ->assertForbidden();

    expect($folder->fresh()->is_public)->toBeFalse();
});

test('an owner can create an empty folder up front', function () {
    $user = folderUser();
    $collection = $user->defaultCollection();

    $this->actingAs($user)
        ->post('/collection/folders', ['collection_id' => $collection->id, 'name' => 'For trade'])
        ->assertRedirect();

    expect($collection->folders()->where('name', 'For trade')->exists())->toBeTrue();

    // The empty folder is listed on the collection page (count 0).
    $this->actingAs($user)->get('/collection')
        ->assertInertia(fn (Assert $p) => $p
            ->where('folders.0.name', 'For trade')
            ->where('folders.0.items_count', 0));
});

test('the owner folder page shows only that folder’s holdings; others 403', function () {
    $user = folderUser();
    addToFolder($user, 'Graded', 'Charizard');
    addToFolder($user, 'Raw', 'Squirtle');
    $folder = $user->defaultCollection()->ensureFolder('Graded');

    $this->actingAs($user)->get("/collection/folders/{$folder->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('collection/folder')
            ->where('folder.name', 'Graded')
            ->where('collection.id', $user->defaultCollection()->id)
            ->has('holdings', 1));

    $this->actingAs(folderUser())
        ->get("/collection/folders/{$folder->id}")
        ->assertForbidden();
});

test('deleting a folder un-files its cards but keeps them in the collection', function () {
    $user = folderUser();
    addToFolder($user, 'Graded', 'Charizard');
    $folder = $user->defaultCollection()->ensureFolder('Graded');

    $this->actingAs($user)
        ->delete("/collection/folders/{$folder->id}")
        ->assertRedirect();

    expect($user->defaultCollection()->folders()->whereKey($folder->id)->exists())->toBeFalse()
        ->and($user->collectionItems()->count())->toBe(1) // card still owned
        ->and($user->collectionItems()->whereNotNull('folder')->count())->toBe(0); // just un-filed
});
