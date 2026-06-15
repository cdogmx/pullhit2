<?php

use App\Models\CatalogItem;
use App\Models\ItemEditSuggestion;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);

    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $set = Set::factory()->for($line)->create(['slug' => 'base']);
    $this->item = CatalogItem::factory()->for($vertical)->for($line)->for($set)->create([
        'name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo'],
    ]);
});

test('a logged-in user can submit an edit — only the diff is stored', function () {
    $this->actingAs($this->user)->post("/catalog/{$this->item->id}/suggestions", [
        'name' => 'Charizard',            // unchanged
        'number' => '4',                  // unchanged
        'rarity' => 'Rare Holo',          // unchanged
        'variant' => 'holo',              // unchanged
        'edition' => '',                  // unchanged (none)
        'language' => 'en',               // unchanged
        'illustrator' => 'Mitsuhiro Arita', // changed (was absent)
        'note' => 'Added the illustrator.',
    ])->assertRedirect();

    $s = ItemEditSuggestion::sole();
    expect($s->status)->toBe('pending')
        ->and($s->user_id)->toBe($this->user->id)
        ->and($s->changes)->toBe(['illustrator' => 'Mitsuhiro Arita'])
        ->and($s->note)->toBe('Added the illustrator.');
});

test('resubmitting updates the open suggestion instead of duplicating', function () {
    $this->actingAs($this->user)->post("/catalog/{$this->item->id}/suggestions", ['name' => 'Charizard', 'illustrator' => 'A']);
    $this->actingAs($this->user)->post("/catalog/{$this->item->id}/suggestions", ['name' => 'Charizard', 'illustrator' => 'B']);

    expect(ItemEditSuggestion::count())->toBe(1)
        ->and(ItemEditSuggestion::sole()->changes)->toBe(['illustrator' => 'B']);
});

test('approving applies the change to the catalog item and rehashes', function () {
    $s = ItemEditSuggestion::create([
        'user_id' => $this->user->id, 'catalog_item_id' => $this->item->id,
        'changes' => ['rarity' => 'Rare Secret', 'illustrator' => 'Arita'], 'status' => 'pending',
    ]);
    $oldHash = $this->item->identity_hash;

    $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve")->assertRedirect();

    $this->item->refresh();
    expect($this->item->attributes['rarity'])->toBe('Rare Secret')
        ->and($this->item->attributes['illustrator'])->toBe('Arita')
        ->and($this->item->identity_hash)->not->toBe($oldHash) // facets changed → rehashed
        ->and($s->fresh()->status)->toBe('approved')
        ->and($s->fresh()->reviewed_by)->toBe($this->admin->id);
});

test('rejecting dismisses the suggestion without touching the card', function () {
    $s = ItemEditSuggestion::create([
        'user_id' => $this->user->id, 'catalog_item_id' => $this->item->id,
        'changes' => ['name' => 'Wrong Name'], 'status' => 'pending',
    ]);

    $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/reject")->assertRedirect();

    expect($s->fresh()->status)->toBe('rejected')
        ->and($this->item->fresh()->name)->toBe('Charizard');
});

test('the review queue is admin-only', function () {
    $this->actingAs($this->user)->get('/admin/suggestions')->assertForbidden();
    $this->actingAs($this->admin)->get('/admin/suggestions')->assertOk();
});

test('an invalid variant is rejected at submission', function () {
    $this->actingAs($this->user)->post("/catalog/{$this->item->id}/suggestions", [
        'name' => 'Charizard', 'variant' => 'bogus',
    ])->assertSessionHasErrors('variant');
});

test('submitting an edit requires auth', function () {
    $this->post("/catalog/{$this->item->id}/suggestions", ['name' => 'x'])->assertRedirect('/login');
});
