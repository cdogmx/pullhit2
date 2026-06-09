<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

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

    $this->get("/catalog/{$item->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('item.data.name', 'Weedle')
            ->where('item.data.attributes.illustrator', 'sowsow')
            ->has('item.data.variants', 2));
});

test('the api returns the item with its variants', function () {
    $item = CatalogItem::where('number', '001/086')->first();

    $this->getJson("/api/v1/catalog/{$item->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Weedle')
        ->assertJsonCount(2, 'data.variants')
        ->assertJsonPath('data.attributes.rarity', 'Common');
});

test('a missing item returns 404', function () {
    $this->getJson('/api/v1/catalog/999999')->assertNotFound();
});
