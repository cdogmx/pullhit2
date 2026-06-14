<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
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

test('the browse page renders cards once a set is chosen', function () {
    // Smart browse drops to the card list when a set is selected.
    $this->get('/browse?set=chaos-rising-en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/browse')
            ->where('mode', 'cards')
            ->has('items', 4)
            ->where('pagination.total', 4)
            ->has('options.rarities')
            ->where('filters.sort', 'number'));
});

test('the browse page exposes pagination for infinite scroll', function () {
    // 4 seeded items, 2 per page -> 2 pages.
    $this->get('/browse?set=chaos-rising-en&per_page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 2)
            ->where('pagination.page', 1)
            ->where('pagination.last_page', 2)
            ->where('pagination.has_more', true));

    $this->get('/browse?set=chaos-rising-en&per_page=2&page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 2)
            ->where('pagination.page', 2)
            ->where('pagination.has_more', false));
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

test('sorting by price orders by the headline market value', function () {
    $cheap = CatalogItem::factory()->create(['name' => 'Cheap Card']);
    $pricey = CatalogItem::factory()->create(['name' => 'Pricey Card']);
    MarketValue::factory()->for($cheap)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'median' => 500,
    ]);
    MarketValue::factory()->for($pricey)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'median' => 9000,
    ]);

    $names = collect($this->getJson('/api/v1/catalog?sort=price&direction=desc')->json('data'))
        ->pluck('name')->values();

    expect($names->first())->toBe('Pricey Card')
        ->and($names->search('Pricey Card'))->toBeLessThan($names->search('Cheap Card'));
});

test('sorting by % change orders by 30-day trend', function () {
    $riser = CatalogItem::factory()->create(['name' => 'Riser']);
    $faller = CatalogItem::factory()->create(['name' => 'Faller']);
    MarketValue::factory()->for($riser)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'trend_30d' => 25.0,
    ]);
    MarketValue::factory()->for($faller)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'trend_30d' => -10.0,
    ]);

    $names = collect($this->getJson('/api/v1/catalog?sort=change&direction=desc')->json('data'))
        ->pluck('name')->values();

    expect($names->search('Riser'))->toBeLessThan($names->search('Faller'));
});

test('per_page paginates results', function () {
    $this->getJson('/api/v1/catalog?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.last_page', 2);
});
