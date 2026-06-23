<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Catalog\UpdateCatalogItem;
use App\Actions\Valuation\IngestEbaySoldComps;
use App\Enums\ItemType;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    $this->vertical = Vertical::create(['slug' => 'tcg', 'name' => 'Trading Card Games']);
    $this->pl = ProductLine::create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::create([
        'product_line_id' => $this->pl->id, 'slug' => 'sv8-en', 'name' => 'Surging Sparks',
        'code' => 'SSP', 'language' => 'en',
    ]);
    $this->create = app(CreateCatalogItem::class);
});

function adminTestCard($t, string $name, string $number, string $variant = 'normal')
{
    return ($t->create)(
        $t->vertical, $t->pl, $t->set, ItemType::Single, $name, $number,
        ['language' => 'en', 'rarity' => 'Common', 'variant' => $variant],
    );
}

test('editing a facet updates the card in place', function () {
    $item = adminTestCard($this, 'Pikachu', '1');

    app(UpdateCatalogItem::class)($item, ['rarity' => 'Illustration Rare']);

    $fresh = $item->fresh();
    expect($fresh->id)->toBe($item->id)
        ->and($fresh->attributes['rarity'])->toBe('Illustration Rare');
});

test('changing the number recomputes the identity hash', function () {
    $item = adminTestCard($this, 'Pikachu', '1');
    $old = $item->identity_hash;

    app(UpdateCatalogItem::class)($item, ['number' => '5']);

    expect($item->fresh()->number)->toBe('5')
        ->and($item->fresh()->identity_hash)->not->toBe($old);
});

test('an edit that would collide with another card is rejected', function () {
    adminTestCard($this, 'Pikachu', '1');
    $b = adminTestCard($this, 'Pikachu', '2');

    expect(fn () => app(UpdateCatalogItem::class)($b, ['number' => '1']))
        ->toThrow(ValidationException::class);
});

test('the cards index filters by rarity and exposes filter options', function () {
    adminTestCard($this, 'Pikachu', '1');
    ($this->create)($this->vertical, $this->pl, $this->set, ItemType::Single, 'Charizard', '4',
        ['language' => 'en', 'rarity' => 'Rare', 'variant' => 'holo']);

    $this->actingAs($this->admin)->get('/admin/cards?rarity=Rare')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/cards')
            ->has('items', 1)
            ->where('items.0.name', 'Charizard')
            ->has('options.rarities')
            ->where('filters.rarity', 'Rare'));
});

test('an admin can force a synchronous eBay refresh (bypasses the guard)', function () {
    $item = adminTestCard($this, 'Pikachu', '1');

    // The pull runs inline; mock the ingest so no real Oxylabs call is made.
    $this->mock(IngestEbaySoldComps::class)
        ->shouldReceive('__invoke')->once()->andReturn(3);

    $this->actingAs($this->admin)->postJson("/admin/cards/{$item->id}/refresh")
        ->assertOk()
        ->assertJson(['ok' => true, 'ingested' => 3]);
});

test('a non-admin cannot force a refresh', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $item = adminTestCard($this, 'Pikachu', '1');

    $this->actingAs($user)->postJson("/admin/cards/{$item->id}/refresh")->assertForbidden();
});

test('the endpoint updates and deletes a card', function () {
    $item = adminTestCard($this, 'Pikachu', '1');
    MarketValue::factory()->for($item)->create(['state_key' => 'NM']);

    $this->actingAs($this->admin)->patch("/admin/cards/{$item->id}", ['rarity' => 'Rare'])->assertRedirect();
    expect($item->fresh()->attributes['rarity'])->toBe('Rare');

    $this->actingAs($this->admin)->delete("/admin/cards/{$item->id}")->assertRedirect();
    $this->assertDatabaseMissing('catalog_items', ['id' => $item->id]);
    $this->assertDatabaseMissing('market_values', ['catalog_item_id' => $item->id]);
});
