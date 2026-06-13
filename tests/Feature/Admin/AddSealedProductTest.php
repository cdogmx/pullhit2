<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;

beforeEach(function () {
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->forceFill(['is_admin' => true])->save();

    $vertical = Vertical::factory()->create(['slug' => 'tcg', 'name' => 'Trading Card Games']);
    $line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::factory()->for($line)->create(['name' => 'Surging Sparks', 'language' => 'en']);
});

test('an admin can add a sealed product to a set', function () {
    $this->actingAs($this->admin)
        ->post("/admin/sets/{$this->set->id}/sealed", [
            'name' => 'Surging Sparks Booster Box',
            'sealed_type' => 'booster_box',
            'language' => 'en',
            'pack_count' => 36,
            'price' => 129.99,
        ])
        ->assertRedirect();

    $item = CatalogItem::where('set_id', $this->set->id)->where('item_type', 'sealed')->first();

    expect($item)->not->toBeNull()
        ->and($item->name)->toBe('Surging Sparks Booster Box')
        ->and($item->attributes['sealed_type'])->toBe('booster_box')
        ->and($item->attributes['language'])->toBe('en')
        ->and($item->attributes['pack_count'])->toBe(36);

    // The price seeded an estimated SEALED-state market value.
    expect(MarketValue::where('catalog_item_id', $item->id)->where('condition', 'SEALED')->exists())
        ->toBeTrue();
});

test('it validates the sealed type against the registry', function () {
    $this->actingAs($this->admin)
        ->post("/admin/sets/{$this->set->id}/sealed", [
            'name' => 'Bad Box',
            'sealed_type' => 'not_a_real_type',
            'language' => 'en',
        ])
        ->assertSessionHasErrors('sealed_type');

    expect(CatalogItem::where('set_id', $this->set->id)->count())->toBe(0);
});

test('a non-admin cannot add sealed products', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->post("/admin/sets/{$this->set->id}/sealed", [
            'name' => 'Sneaky Box',
            'sealed_type' => 'booster_box',
            'language' => 'en',
        ])
        ->assertForbidden();

    expect(CatalogItem::where('set_id', $this->set->id)->count())->toBe(0);
});
