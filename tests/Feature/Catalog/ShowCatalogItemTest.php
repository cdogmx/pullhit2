<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon']);
    $this->set = Set::factory()->create(['product_line_id' => $this->pokemon->id, 'slug' => 'chaos-rising-en', 'code' => 'CRI', 'language' => 'en']);

    $create = app(CreateCatalogItem::class);

    foreach (['normal', 'reverse_holo'] as $variant) {
        $create(
            vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
            itemType: ItemType::Single, name: 'Weedle', number: '001/086',
            attributes: ['language' => 'en', 'rarity' => 'Common', 'variant' => $variant, 'illustrator' => 'sowsow', 'hp' => 50, 'type' => 'Grass'],
        );
    }
});

test('the detail page renders for guests with the item and its printings', function () {
    $item = CatalogItem::where('number', '001/086')->where('attributes->variant', 'normal')->first();

    // /catalog/{id} 301s to the canonical /{brand}/{set}/{card} URL; follow it.
    $this->followingRedirects()->get("/catalog/{$item->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('item.data.name', 'Weedle')
            ->where('item.data.attributes.illustrator', 'sowsow')
            ->has('item.data.variants', 2));
});

test('the detail page lists same-rarity cards in the set, excluding this card', function () {
    $create = app(CreateCatalogItem::class);
    // Two more Commons (should appear) + one Rare (should be excluded by rarity).
    foreach (['Caterpie' => '002/086', 'Metapod' => '003/086'] as $name => $number) {
        $create(
            vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
            itemType: ItemType::Single, name: $name, number: $number,
            attributes: ['language' => 'en', 'rarity' => 'Common', 'variant' => 'normal'],
        );
    }
    $create(
        vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
        itemType: ItemType::Single, name: 'Charizard', number: '004/086',
        attributes: ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo'],
    );

    $weedle = CatalogItem::where('name', 'Weedle')->where('attributes->variant', 'normal')->first();

    $this->followingRedirects()->get("/catalog/{$weedle->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->has('moreInSet', 2) // the two Commons; the Rare Holo Charizard is excluded
            ->where('moreInSet.0.name', fn ($n) => in_array($n, ['Caterpie', 'Metapod'], true)));
});

test('the api returns the item with its variants', function () {
    // The JSON detail endpoint is token-only (the web uses the Inertia page).
    Sanctum::actingAs(User::factory()->create());
    $item = CatalogItem::where('number', '001/086')->first();

    $this->getJson("/api/v1/catalog/{$item->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Weedle')
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.attributes.rarity', 'Common');
});

test('a missing item returns 404', function () {
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/catalog/999999')->assertNotFound();
});
