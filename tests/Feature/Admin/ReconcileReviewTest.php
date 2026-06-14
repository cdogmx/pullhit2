<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\ReconciliationChange;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use App\Support\Reconcile\ReconcileChange;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($this->vertical)->create(['slug' => 'pokemon']);
    $this->set = Set::factory()->for($this->line)->create(['slug' => 'base', 'name' => 'Base', 'language' => 'en']);
    $this->base = CatalogItem::factory()->for($this->vertical)->for($this->line)->for($this->set)->create([
        'name' => 'Charizard', 'number' => '4',
        'attributes' => ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo'],
    ]);

    $this->change = ReconciliationChange::create([
        'pc_id' => '999', 'set_id' => $this->set->id,
        'action' => ReconcileChange::ADD_ERROR_VARIANT, 'reason' => 'missing_printing',
        'confidence' => 'high', 'status' => 'pending',
        'payload' => [
            'label' => 'Charizard [Black Dot Error] #4', 'name' => 'Charizard', 'number' => '4',
            'attributes' => ['variant' => 'holo', 'edition' => 'unlimited', 'finish' => 'black_dot_error'],
            'base_item_id' => $this->base->id, 'prices' => ['ungraded' => 35500],
        ],
    ]);
});

test('the review queue renders for admins only', function () {
    $this->actingAs($this->admin)->get('/admin/reconcile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/reconcile')->where('pending', 1));

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/admin/reconcile')->assertForbidden();
});

test('approving a queued change creates the item and marks it applied', function () {
    $this->actingAs($this->admin)->post("/admin/reconcile/{$this->change->id}/approve")->assertRedirect();

    expect($this->change->fresh()->status)->toBe('applied');

    $error = CatalogItem::where('set_id', $this->set->id)->where('number', '4')->get()
        ->first(fn ($i) => ($i->attributes['finish'] ?? null) === 'black_dot_error');

    expect($error)->not->toBeNull()
        ->and($error->base_key)->toHaveLength(64)
        ->and($error->external_ids['pricecharting_id'])->toBe('999');
});

test('skipping a change dismisses it without a write', function () {
    $this->actingAs($this->admin)->post("/admin/reconcile/{$this->change->id}/skip")->assertRedirect();

    expect($this->change->fresh()->status)->toBe('skipped')
        ->and(CatalogItem::where('set_id', $this->set->id)->count())->toBe(1); // only the base
});

test('batch-approve applies every pending change of an action for a set', function () {
    ReconciliationChange::create([
        'pc_id' => '1000', 'set_id' => $this->set->id, 'action' => ReconcileChange::ADD_ERROR_VARIANT,
        'reason' => 'missing_printing', 'confidence' => 'high', 'status' => 'pending',
        'payload' => ['name' => 'Charizard', 'number' => '4', 'base_item_id' => $this->base->id,
            'attributes' => ['variant' => 'holo', 'edition' => 'unlimited', 'finish' => 'cracked_ice'], 'prices' => []],
    ]);

    $this->actingAs($this->admin)->post('/admin/reconcile/approve-batch', [
        'set_id' => $this->set->id, 'action' => ReconcileChange::ADD_ERROR_VARIANT,
    ])->assertRedirect();

    expect(ReconciliationChange::where('status', 'pending')->count())->toBe(0)
        ->and(CatalogItem::where('set_id', $this->set->id)->where('number', '4')->count())->toBe(3); // base + 2 errors
});
