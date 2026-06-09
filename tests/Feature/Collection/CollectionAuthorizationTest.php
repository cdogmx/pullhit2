<?php

use App\Models\CollectionItem;
use App\Models\User;

beforeEach(function () {
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->other = User::factory()->create(['email_verified_at' => now()]);
    $this->holding = CollectionItem::factory()->for($this->owner)->create();
});

test('a user cannot update another user\'s holding', function () {
    $this->actingAs($this->other)
        ->patch("/collection/{$this->holding->id}", ['quantity' => 5])
        ->assertForbidden();
});

test('a user cannot delete another user\'s holding', function () {
    $this->actingAs($this->other)
        ->delete("/collection/{$this->holding->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('collection_items', ['id' => $this->holding->id]);
});

test('the owner can update and delete their own holding', function () {
    $this->actingAs($this->owner)
        ->patch("/collection/{$this->holding->id}", ['is_for_sale' => true])
        ->assertRedirect();

    expect($this->holding->fresh()->is_for_sale)->toBeTrue();

    $this->actingAs($this->owner)
        ->delete("/collection/{$this->holding->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('collection_items', ['id' => $this->holding->id]);
});
