<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->pokemon = ProductLine::factory()->create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon']);
    $this->set = Set::factory()->create(['product_line_id' => $this->pokemon->id, 'slug' => 'chaos-rising-en', 'code' => 'CRI', 'language' => 'en']);

    $create = app(CreateCatalogItem::class);

    // Weedle: two printings of one base card.
    foreach (['normal', 'reverse_holo'] as $variant) {
        $create(
            vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
            itemType: ItemType::Single, name: 'Weedle', number: '001/086',
            attributes: ['language' => 'en', 'rarity' => 'Common', 'variant' => $variant],
        );
    }

    // Pikachu ex: single holo printing.
    $create(
        vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
        itemType: ItemType::Single, name: 'Pikachu ex', number: '050/086',
        attributes: ['language' => 'en', 'rarity' => 'Double Rare', 'variant' => 'holo'],
    );

    // A sealed product.
    $create(
        vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
        itemType: ItemType::Sealed, name: 'Chaos Rising Booster Box',
        attributes: ['sealed_type' => 'booster_box', 'language' => 'en', 'pack_count' => 36],
    );
});

test('the browse page renders for guests with catalog items', function () {
    $this->get('/browse')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/browse')
            ->has('items.data', 4)
            ->has('options.rarities')
            ->where('filters.sort', 'number'));
});

test('the api returns catalog items with pagination', function () {
    $this->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta', 'options' => ['sets', 'variants', 'rarities']])
        ->assertJsonCount(4, 'data');
});

test('search narrows by name', function () {
    $this->getJson('/api/v1/catalog?q=weedle')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('filtering by rarity, variant, and item_type each narrows results', function () {
    $this->getJson('/api/v1/catalog?rarity=Common')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/catalog?variant=holo')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/catalog?item_type=sealed')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/catalog?item_type=single')->assertJsonCount(3, 'data');
});

test('grouping by base collapses printings and reports the count', function () {
    $response = $this->getJson('/api/v1/catalog?group=1&item_type=single')->assertOk();

    // 3 single printings (Weedle x2 + Pikachu) collapse to 2 base cards.
    $response->assertJsonCount(2, 'data');

    $weedle = collect($response->json('data'))->firstWhere('name', 'Weedle');
    expect($weedle['variants_count'])->toBe(2);
});

test('sorting by name orders results alphabetically', function () {
    $names = collect($this->getJson('/api/v1/catalog?sort=name&direction=asc')->json('data'))
        ->pluck('name');

    expect($names->first())->toBe('Chaos Rising Booster Box');
});

test('per_page paginates results', function () {
    $this->getJson('/api/v1/catalog?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.last_page', 2);
});
