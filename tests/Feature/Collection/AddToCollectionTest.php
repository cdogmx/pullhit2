<?php

use App\Actions\Collection\AddToCollection;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->item = CatalogItem::factory()->create();
});

test('it creates a holding with an acquisition lot and cost basis', function () {
    $holding = app(AddToCollection::class)($this->user, $this->item, [
        'condition' => 'NM',
        'quantity' => 2,
        'unit_cost' => 1000,   // $10.00
        'fees' => 200,
        'acquired_at' => '2026-01-01',
    ]);

    expect($holding->quantity)->toBe(2)
        ->and($holding->acquisitionLots()->count())->toBe(1)
        ->and($holding->costBasisCents())->toBe(2200); // 2×1000 + 200

    $this->assertDatabaseHas('collection_items', [
        'user_id' => $this->user->id,
        'catalog_item_id' => $this->item->id,
        'condition' => 'NM',
        'quantity' => 2,
    ]);
});

test('re-adding the same state merges into one holding and appends a lot', function () {
    $add = app(AddToCollection::class);
    $add($this->user, $this->item, ['condition' => 'NM', 'quantity' => 2, 'unit_cost' => 1000]);
    $holding = $add($this->user, $this->item, ['condition' => 'NM', 'quantity' => 1, 'unit_cost' => 1200]);

    expect(CatalogItem::find($this->item->id)->id)->toBe($this->item->id);
    expect($this->user->collectionItems()->count())->toBe(1)
        ->and($holding->fresh()->quantity)->toBe(3)
        ->and($holding->acquisitionLots()->count())->toBe(2)
        ->and($holding->costBasisCents())->toBe(3200); // 2000 + 1200
});

test('the store endpoint accepts a folder', function () {
    $this->actingAs($this->user)
        ->post('/collection', [
            'catalog_item_id' => $this->item->id,
            'condition' => 'NM',
            'quantity' => 1,
            'unit_cost' => 0,
            'folder' => 'Trade binder',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('collection_items', [
        'user_id' => $this->user->id,
        'catalog_item_id' => $this->item->id,
        'folder' => 'Trade binder',
    ]);
});

test('the owned endpoint reports how many copies the user already has', function () {
    app(AddToCollection::class)($this->user, $this->item, [
        'condition' => 'NM', 'quantity' => 3, 'unit_cost' => 0,
    ]);

    $this->actingAs($this->user)
        ->getJson("/collection/owned/{$this->item->id}")
        ->assertOk()
        ->assertJsonPath('quantity', 3);

    // A card the user doesn't own reports zero.
    $other = CatalogItem::factory()->create();
    $this->actingAs($this->user)
        ->getJson("/collection/owned/{$other->id}")
        ->assertOk()
        ->assertJsonPath('quantity', 0);
});

test('a graded copy is stored without a raw condition', function () {
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    $holding = app(AddToCollection::class)($this->user, $this->item, [
        'condition' => 'NM', // should be dropped for a graded copy
        'grading_company_id' => $psa->id,
        'grade' => 10,
        'quantity' => 1,
        'unit_cost' => 50000,
    ]);

    expect($holding->condition)->toBeNull()
        ->and($holding->grade)->toBe(10.0)
        ->and($holding->stateKey())->toBe('psa-10');
});
