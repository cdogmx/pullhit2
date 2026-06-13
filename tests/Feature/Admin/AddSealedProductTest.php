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
            'msrp' => 161.64,
            'released_at' => '2024-11-08',
            'retailer_links' => [
                ['retailer' => 'Target', 'url' => 'https://target.com/x', 'price' => 159.99],
                ['retailer' => 'Walmart', 'url' => 'https://walmart.com/y', 'price' => 149.99],
                // Incomplete row (no url) should be dropped.
                ['retailer' => 'Costco', 'url' => '', 'price' => 139.99],
            ],
        ])
        ->assertRedirect();

    $item = CatalogItem::where('set_id', $this->set->id)->where('item_type', 'sealed')->first();

    expect($item)->not->toBeNull()
        ->and($item->name)->toBe('Surging Sparks Booster Box')
        ->and($item->attributes['sealed_type'])->toBe('booster_box')
        ->and($item->attributes['language'])->toBe('en')
        ->and($item->attributes['pack_count'])->toBe(36)
        ->and($item->msrp)->toBe(16164) // cents
        ->and($item->released_at->toDateString())->toBe('2024-11-08')
        ->and($item->retailer_links)->toHaveCount(2); // Costco dropped

    expect($item->retailer_links[0])
        ->toMatchArray(['retailer' => 'Target', 'url' => 'https://target.com/x', 'price_cents' => 15999]);

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

test('an admin can edit a sealed product', function () {
    $this->actingAs($this->admin)->post("/admin/sets/{$this->set->id}/sealed", [
        'name' => 'Surging Sparks Booster Box',
        'sealed_type' => 'booster_box',
        'language' => 'en',
        'price' => 100,
        'retailer_links' => [['retailer' => 'Target', 'url' => 'https://target.com/x', 'price' => 99]],
    ]);
    $item = CatalogItem::where('item_type', 'sealed')->firstOrFail();

    $this->actingAs($this->admin)->patch("/admin/sealed/{$item->id}", [
        'name' => 'Surging Sparks Elite Trainer Box',
        'sealed_type' => 'elite_trainer_box',
        'language' => 'en',
        'pack_count' => 9,
        'msrp' => 49.99,
        'released_at' => '2024-11-08',
        'retailer_links' => [
            ['retailer' => 'Walmart', 'url' => 'https://walmart.com/z', 'price' => 44.99],
        ],
    ])->assertRedirect();

    $item->refresh();
    expect($item->name)->toBe('Surging Sparks Elite Trainer Box')
        ->and($item->attributes['sealed_type'])->toBe('elite_trainer_box')
        ->and($item->attributes['pack_count'])->toBe(9)
        ->and($item->msrp)->toBe(4999)
        ->and($item->retailer_links)->toHaveCount(1)
        ->and($item->retailer_links[0]['price_cents'])->toBe(4499);

    // No duplicate created by the edit.
    expect(CatalogItem::where('item_type', 'sealed')->count())->toBe(1);
});

test('the sealed update 404s for a non-sealed item', function () {
    $single = CatalogItem::factory()->for($this->set)->create([
        'attributes' => ['language' => 'en', 'variant' => 'normal'],
    ]);

    $this->actingAs($this->admin)
        ->patch("/admin/sealed/{$single->id}", [
            'name' => 'x',
            'sealed_type' => 'booster_box',
            'language' => 'en',
        ])
        ->assertNotFound();
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
