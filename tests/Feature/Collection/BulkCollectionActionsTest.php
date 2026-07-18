<?php

use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\User;

function bulkUser(): User
{
    return User::factory()->create([
        'username' => 'bulk'.fake()->unique()->numberBetween(1, 999999),
        'email_verified_at' => now(),
        'membership_tier' => 'guru',
    ]);
}

function holdingFor(User $user, ?int $collectionId = null): CollectionItem
{
    return $user->collectionItems()->create([
        'collection_id' => $collectionId ?? $user->defaultCollection()->id,
        'catalog_item_id' => CatalogItem::factory()->create()->id,
        'condition' => Condition::NearMint,
        'quantity' => 1,
    ]);
}

test('it bulk-moves holdings into another collection and folder', function () {
    $user = bulkUser();
    $target = $user->collections()->create(['name' => 'Graded', 'slug' => 'graded', 'sort' => 1]);
    $a = holdingFor($user);
    $b = holdingFor($user);

    $this->actingAs($user)
        ->patch('/collection/bulk', [
            'ids' => [$a->id, $b->id],
            'collection_id' => $target->id,
            'folder' => 'Slabs',
        ])
        ->assertRedirect();

    foreach ([$a, $b] as $h) {
        expect($h->fresh()->collection_id)->toBe($target->id)
            ->and($h->fresh()->folder)->toBe('Slabs');
    }
});

test('bulk move can create a new collection on the fly', function () {
    $user = bulkUser();
    $a = holdingFor($user);

    $this->actingAs($user)
        ->patch('/collection/bulk', [
            'ids' => [$a->id],
            'new_collection_name' => 'Vintage',
        ])
        ->assertRedirect();

    $created = $user->collections()->where('name', 'Vintage')->first();
    expect($created)->not->toBeNull()
        ->and($a->fresh()->collection_id)->toBe($created->id);
});

test('it bulk-removes holdings', function () {
    $user = bulkUser();
    $a = holdingFor($user);
    $b = holdingFor($user);
    $keep = holdingFor($user);

    $this->actingAs($user)
        ->delete('/collection/bulk', ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect(CollectionItem::whereKey([$a->id, $b->id])->count())->toBe(0)
        ->and($keep->fresh())->not->toBeNull();
});

test('bulk actions never touch another user’s holdings', function () {
    $user = bulkUser();
    $other = bulkUser();
    $mine = holdingFor($user);
    $theirs = holdingFor($other);

    // A stale/forged id is silently skipped, not applied and not a 403.
    $this->actingAs($user)
        ->delete('/collection/bulk', ['ids' => [$mine->id, $theirs->id]])
        ->assertRedirect();

    expect($mine->fresh())->toBeNull()
        ->and($theirs->fresh())->not->toBeNull();

    $target = $user->collections()->create(['name' => 'Mine', 'slug' => 'mine', 'sort' => 1]);
    $this->actingAs($user)
        ->patch('/collection/bulk', ['ids' => [$theirs->id], 'collection_id' => $target->id])
        ->assertRedirect();

    expect($theirs->fresh()->collection_id)->not->toBe($target->id);
});

test('a move into a collection holding the same card+state merges instead of duplicating', function () {
    $user = bulkUser();
    $target = $user->collections()->create(['name' => 'Dupes', 'slug' => 'dupes', 'sort' => 1]);
    $card = CatalogItem::factory()->create();

    $existing = $user->collectionItems()->create([
        'collection_id' => $target->id, 'catalog_item_id' => $card->id,
        'condition' => Condition::NearMint, 'quantity' => 2,
    ]);
    $moving = $user->collectionItems()->create([
        'collection_id' => $user->defaultCollection()->id, 'catalog_item_id' => $card->id,
        'condition' => Condition::NearMint, 'quantity' => 3,
    ]);

    $this->actingAs($user)
        ->patch('/collection/bulk', ['ids' => [$moving->id], 'collection_id' => $target->id])
        ->assertRedirect();

    expect($existing->fresh()->quantity)->toBe(5)          // folded together
        ->and(CollectionItem::whereKey($moving->id)->exists())->toBeFalse();
});
