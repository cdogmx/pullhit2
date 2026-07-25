<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
        'membership_tier' => 'guru',
    ]);
    $this->cards = CatalogItem::factory()->count(3)->create();
});

test('it adds every selected card with the shared state and cost', function () {
    $this->actingAs($this->user)
        ->post('/collection/bulk', [
            'catalog_item_ids' => $this->cards->pluck('id')->all(),
            'condition' => 'NM',
            'quantity' => 2,
            'unit_cost' => 500,
        ])
        ->assertRedirect();

    $holdings = $this->user->collectionItems()->get();

    expect($holdings)->toHaveCount(3);

    foreach ($holdings as $holding) {
        expect($holding->quantity)->toBe(2)
            ->and($holding->condition->value)->toBe('NM')
            // Each card gets its own acquisition lot, so cost basis survives.
            ->and($holding->acquisitionLots()->count())->toBe(1)
            ->and($holding->costBasisCents())->toBe(1000);
    }
});

test('re-adding a card merges into the existing holding', function () {
    $payload = [
        'catalog_item_ids' => [$this->cards[0]->id],
        'condition' => 'NM',
        'quantity' => 1,
        'unit_cost' => 0,
    ];

    $this->actingAs($this->user)->post('/collection/bulk', $payload);
    $this->actingAs($this->user)->post('/collection/bulk', $payload);

    expect($this->user->collectionItems()->count())->toBe(1)
        ->and($this->user->collectionItems()->first()->quantity)->toBe(2);
});

test('it can add the batch as a graded state', function () {
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    $this->actingAs($this->user)
        ->post('/collection/bulk', [
            'catalog_item_ids' => $this->cards->pluck('id')->all(),
            'grading_company_id' => $psa->id,
            'grade' => 10,
            'quantity' => 1,
            'unit_cost' => 0,
        ])
        ->assertRedirect();

    $holding = $this->user->collectionItems()->first();

    // A graded copy carries the grade and no raw condition.
    expect($holding->grading_company_id)->toBe($psa->id)
        ->and((float) $holding->grade)->toBe(10.0)
        ->and($holding->condition)->toBeNull();
});

test('it can file the batch into a new collection and folder', function () {
    $this->actingAs($this->user)
        ->post('/collection/bulk', [
            'catalog_item_ids' => $this->cards->pluck('id')->all(),
            'new_collection_name' => 'Slabs',
            'folder' => 'Charizards',
            'condition' => 'NM',
            'quantity' => 1,
            'unit_cost' => 0,
        ])
        ->assertRedirect();

    $collection = $this->user->collections()->where('name', 'Slabs')->firstOrFail();

    expect($this->user->collectionItems()->where('collection_id', $collection->id)->count())->toBe(3)
        ->and($this->user->collectionItems()->first()->folder)->toBe('Charizards');
});

test('unknown ids are skipped rather than failing the batch', function () {
    // A stale browse selection shouldn't cost the user the rest of the batch.
    $this->actingAs($this->user)
        ->post('/collection/bulk', [
            'catalog_item_ids' => [$this->cards[0]->id, 999_999],
            'condition' => 'NM',
            'quantity' => 1,
            'unit_cost' => 0,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Added 1 card to your collection.');

    expect($this->user->collectionItems()->count())->toBe(1);
});

test('a batch with no condition and no grader is rejected', function () {
    // Neither a condition nor a grader — there's no priced state to add.
    $this->actingAs($this->user)
        ->post('/collection/bulk', [
            'catalog_item_ids' => $this->cards->pluck('id')->all(),
            'quantity' => 1,
            'unit_cost' => 0,
        ])
        ->assertSessionHasErrors('condition');

    expect($this->user->collectionItems()->count())->toBe(0);
});

test('bulk add requires authentication', function () {
    $this->post('/collection/bulk', [
        'catalog_item_ids' => [$this->cards[0]->id],
        'condition' => 'NM',
        'quantity' => 1,
        'unit_cost' => 0,
    ])->assertRedirect('/login');
});
