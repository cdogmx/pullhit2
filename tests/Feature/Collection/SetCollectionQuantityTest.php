<?php

use App\Actions\Collection\SetCollectionQuantity;
use App\Models\CatalogItem;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->item = CatalogItem::factory()->create();
    $this->set = app(SetCollectionQuantity::class);
    $this->qty = fn () => (int) $this->user->collectionItems()
        ->where('catalog_item_id', $this->item->id)->where('condition', 'NM')->sum('quantity');
    $this->lotSum = function (): int {
        $ci = $this->user->collectionItems()->where('catalog_item_id', $this->item->id)->where('condition', 'NM')->first();

        return $ci ? (int) $ci->acquisitionLots()->sum('quantity') : 0;
    };
});

test('setting a fresh card creates the holding at that quantity, quantity = Σ lots', function () {
    ($this->set)($this->user, $this->item, ['condition' => 'NM', 'unit_cost' => 500], 3);

    expect(($this->qty)())->toBe(3)
        ->and(($this->lotSum)())->toBe(3);
});

test('increasing adds a lot for the delta only', function () {
    ($this->set)($this->user, $this->item, ['condition' => 'NM'], 2);
    ($this->set)($this->user, $this->item, ['condition' => 'NM', 'unit_cost' => 900], 5);

    $ci = $this->user->collectionItems()->where('catalog_item_id', $this->item->id)->first();
    expect(($this->qty)())->toBe(5)
        ->and(($this->lotSum)())->toBe(5)
        ->and($ci->acquisitionLots()->count())->toBe(2); // original + delta lot
});

test('decreasing trims lots to keep quantity = Σ lots', function () {
    ($this->set)($this->user, $this->item, ['condition' => 'NM'], 5);
    ($this->set)($this->user, $this->item, ['condition' => 'NM'], 2);

    expect(($this->qty)())->toBe(2)
        ->and(($this->lotSum)())->toBe(2);
});

test('setting to zero removes the holding entirely', function () {
    ($this->set)($this->user, $this->item, ['condition' => 'NM'], 3);
    ($this->set)($this->user, $this->item, ['condition' => 'NM'], 0);

    expect($this->user->collectionItems()->where('catalog_item_id', $this->item->id)->exists())->toBeFalse();
});

test('the endpoint sets the quantity and reports current holdings', function () {
    $this->actingAs($this->user)
        ->post("/collection/{$this->item->id}/quantity", ['condition' => 'NM', 'quantity' => 4])
        ->assertRedirect();

    expect(($this->qty)())->toBe(4);

    $this->actingAs($this->user)
        ->getJson("/collection/holdings/{$this->item->id}")
        ->assertOk()
        ->assertJsonPath('holdings.0.quantity', 4)
        ->assertJsonPath('holdings.0.condition', 'NM');
});

test('patching a holding quantity to zero removes it', function () {
    ($this->set)($this->user, $this->item, ['condition' => 'NM'], 3);
    $holding = $this->user->collectionItems()->where('catalog_item_id', $this->item->id)->first();

    $this->actingAs($this->user)
        ->patch("/collection/{$holding->id}", ['quantity' => 0])
        ->assertRedirect();

    expect($this->user->collectionItems()->where('catalog_item_id', $this->item->id)->exists())->toBeFalse();
});
