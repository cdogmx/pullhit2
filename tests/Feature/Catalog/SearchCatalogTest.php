<?php

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\User;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // The JSON catalog search is token-only (not used by the web); authenticate.
    Sanctum::actingAs(User::factory()->create());

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

test('the browse page renders cards once a set is chosen, defaulting to singles', function () {
    // Smart browse drops to the card list when a set is selected, and leads with
    // individual cards — the 3 singles, not the sealed box (4th item).
    $this->get('/browse?set=chaos-rising-en')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/browse')
            ->where('mode', 'cards')
            ->has('items', 3)
            ->where('pagination.total', 3)
            ->where('filters.item_type', 'single')
            ->has('options.rarities')
            ->where('filters.sort', 'number'));
});

test('the browse page can opt into all types or just sealed', function () {
    $this->get('/browse?set=chaos-rising-en&item_type=all')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items', 4));

    $this->get('/browse?set=chaos-rising-en&item_type=sealed')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('filters.item_type', 'sealed'));
});

test('the browse page exposes pagination for infinite scroll', function () {
    // 4 seeded items (all types), 2 per page -> 2 pages.
    $this->get('/browse?set=chaos-rising-en&item_type=all&per_page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 2)
            ->where('pagination.page', 1)
            ->where('pagination.last_page', 2)
            ->where('pagination.has_more', true));

    $this->get('/browse?set=chaos-rising-en&item_type=all&per_page=2&page=2')
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

test('search lifts edition keywords out of the query', function () {
    $create = app(CreateCatalogItem::class);
    foreach (['first_edition', 'unlimited'] as $edition) {
        $create(
            vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
            itemType: ItemType::Single, name: 'Charizard', number: '4',
            attributes: ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo', 'edition' => $edition],
        );
    }

    // "charizard 1st edition" → name match "charizard" + edition=first_edition.
    $res = $this->getJson('/api/v1/catalog?q='.urlencode('charizard 1st edition'))->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.attributes.edition'))->toBe('first_edition');

    // Plain "charizard" still returns both printings.
    $this->getJson('/api/v1/catalog?q=charizard')->assertJsonCount(2, 'data');
});

test('search matches name + card number together', function () {
    // Pikachu ex is number 050/086 (from beforeEach).
    $this->getJson('/api/v1/catalog?q='.urlencode('pikachu 050/086'))
        ->assertOk()->assertJsonCount(1, 'data');

    // The numerator alone resolves the number too.
    $this->getJson('/api/v1/catalog?q='.urlencode('weedle 1'))
        ->assertOk()->assertJsonCount(2, 'data'); // both Weedle printings are #001/086
});

test('search ranks exact name matches above partial ones', function () {
    $create = app(CreateCatalogItem::class);
    $create(vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
        itemType: ItemType::Single, name: 'Charizard ex', number: '200',
        attributes: ['language' => 'en', 'rarity' => 'Double Rare', 'variant' => 'holo']);
    $create(vertical: $this->vertical, productLine: $this->pokemon, set: $this->set,
        itemType: ItemType::Single, name: 'Charizard', number: '4',
        attributes: ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo']);

    $names = collect($this->getJson('/api/v1/catalog?q=charizard')->json('data'))->pluck('name');
    expect($names->first())->toBe('Charizard'); // exact beats "Charizard ex"
});

test('search ranks the better set-name match first', function () {
    $create = app(CreateCatalogItem::class);
    $base = Set::factory()->create(['product_line_id' => $this->pokemon->id, 'slug' => 'base', 'name' => 'Base', 'language' => 'en']);
    $exp = Set::factory()->create(['product_line_id' => $this->pokemon->id, 'slug' => 'expedition', 'name' => 'Expedition Base Set', 'language' => 'en']);
    foreach ([$exp, $base] as $set) {
        $create(vertical: $this->vertical, productLine: $this->pokemon, set: $set,
            itemType: ItemType::Single, name: 'Charizard', number: '4',
            attributes: ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo']);
    }

    $sets = collect($this->getJson('/api/v1/catalog?q='.urlencode('charizard base set'))->json('data'))
        ->pluck('set.name');
    expect($sets->first())->toBe('Base'); // exact "Base" beats "Expedition Base Set"
});

test('search matches name + set name', function () {
    $base = Set::factory()->create(['product_line_id' => $this->pokemon->id, 'slug' => 'base', 'name' => 'Base', 'language' => 'en']);
    app(CreateCatalogItem::class)(
        vertical: $this->vertical, productLine: $this->pokemon, set: $base,
        itemType: ItemType::Single, name: 'Charizard', number: '4',
        attributes: ['language' => 'en', 'rarity' => 'Rare Holo', 'variant' => 'holo'],
    );

    // "set" is a stopword; "charizard" → name, "base" → set name.
    $res = $this->getJson('/api/v1/catalog?q='.urlencode('charizard base set'))->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.name'))->toBe('Charizard');
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

test('filtering by grader returns only graded cards and shows the graded value', function () {
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    $graded = CatalogItem::factory()->create(['name' => 'Graded Card']);
    MarketValue::factory()->for($graded)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'grade' => null, 'median' => 1000,
    ]);
    MarketValue::factory()->for($graded)->create([
        'state_key' => 'PSA-10', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10, 'median' => 50000,
    ]);

    // A raw-only card must be excluded by the grader filter.
    $rawOnly = CatalogItem::factory()->create(['name' => 'Raw Only Card']);
    MarketValue::factory()->for($rawOnly)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'grade' => null, 'median' => 2000,
    ]);

    $data = collect($this->getJson('/api/v1/catalog?grading_company=psa')->json('data'));

    expect($data->pluck('name'))->toContain('Graded Card')->not->toContain('Raw Only Card');

    // The displayed value is the graded one, not the raw NM value.
    $card = $data->firstWhere('name', 'Graded Card');
    expect($card['market_value']['median'])->toBe(50000)
        ->and((float) $card['market_value']['grade'])->toBe(10.0);
});

test('filtering by grade narrows to the exact grade', function () {
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    $card = CatalogItem::factory()->create(['name' => 'Two Grades Card']);
    MarketValue::factory()->for($card)->create([
        'state_key' => 'PSA-10', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10, 'median' => 50000,
    ]);
    MarketValue::factory()->for($card)->create([
        'state_key' => 'PSA-9', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 9, 'median' => 20000,
    ]);

    $card9 = collect($this->getJson('/api/v1/catalog?grading_company=psa&grade=9')->json('data'))
        ->firstWhere('name', 'Two Grades Card');

    expect((float) $card9['market_value']['grade'])->toBe(9.0)
        ->and($card9['market_value']['median'])->toBe(20000);
});

test('sorting by price uses the graded value when browsing a grade', function () {
    $psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);

    // Raw order is the reverse of graded order — proves the sort reads the grade.
    $a = CatalogItem::factory()->create(['name' => 'Grade Sort A']);
    MarketValue::factory()->for($a)->create(['state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'grade' => null, 'median' => 9000]);
    MarketValue::factory()->for($a)->create(['state_key' => 'PSA-10', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10, 'median' => 10000]);

    $b = CatalogItem::factory()->create(['name' => 'Grade Sort B']);
    MarketValue::factory()->for($b)->create(['state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null, 'grade' => null, 'median' => 12000]);
    MarketValue::factory()->for($b)->create(['state_key' => 'PSA-10', 'condition' => null, 'grading_company_id' => $psa->id, 'grade' => 10, 'median' => 80000]);

    $names = collect($this->getJson('/api/v1/catalog?grading_company=psa&sort=price&direction=desc')->json('data'))
        ->pluck('name')->values();

    // By graded median B (80000) outranks A (10000), the opposite of raw order.
    expect($names->search('Grade Sort B'))->toBeLessThan($names->search('Grade Sort A'));
});

test('per_page paginates results', function () {
    $this->getJson('/api/v1/catalog?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.last_page', 2);
});
