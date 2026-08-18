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

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    $this->vertical = Vertical::create(['slug' => 'tcg', 'name' => 'Trading Card Games']);
    $this->pl = ProductLine::create(['vertical_id' => $this->vertical->id, 'slug' => 'pokemon', 'name' => 'Pokémon']);
    $this->set = Set::create([
        'product_line_id' => $this->pl->id, 'slug' => 'unbroken-bonds',
        'name' => 'Unbroken Bonds', 'code' => 'UNB', 'language' => 'en',
    ]);
    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
});

function inversionCard(string $name, string $number): CatalogItem
{
    return app(CreateCatalogItem::class)(
        vertical: test()->vertical,
        productLine: test()->pl,
        set: test()->set,
        itemType: ItemType::Single,
        name: $name,
        number: $number,
        attributes: ['language' => 'en', 'rarity' => 'Rare', 'variant' => 'holo'],
    );
}

/** @param  array<string, mixed>  $overrides */
function marketValue(CatalogItem $item, string $state, int $median, array $overrides = []): MarketValue
{
    return MarketValue::factory()->create(array_merge([
        'catalog_item_id' => $item->id,
        'state_key' => $state,
        'median' => $median,
        'n_sales' => 5,
        'is_estimated' => false,
        'grading_company_id' => null,
        'grade' => null,
    ], $overrides));
}

function graded(CatalogItem $item, string $state, int $median, float $grade, array $overrides = []): MarketValue
{
    return marketValue($item, $state, $median, array_merge([
        'grading_company_id' => test()->psa->id,
        'grade' => $grade,
    ], $overrides));
}

test('it lists a graded value that sits below its own raw value', function () {
    $bad = inversionCard('Muk & Alolan Muk-GX', '220');
    marketValue($bad, 'NM', 3_000);
    graded($bad, 'psa-9', 500, 9.0);

    $this->actingAs($this->admin)->get('/admin/price-inversions')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/price-inversions')
            ->has('rows', 1)
            ->where('rows.0.name', 'Muk & Alolan Muk-GX')
            ->where('rows.0.raw', 3_000)
            ->where('rows.0.graded', 500)
            // The shortfall is what makes it worth looking at.
            ->where('rows.0.gap', 2_500)
        );
});

test('a healthy card — graded worth more than raw — is not listed', function () {
    $good = inversionCard('Charizard', '4');
    marketValue($good, 'NM', 1_000);
    graded($good, 'psa-9', 9_000, 9.0);

    $this->actingAs($this->admin)->get('/admin/price-inversions')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

test('grades below the floor are excluded', function () {
    $card = inversionCard('Pikachu', '58');
    marketValue($card, 'NM', 3_000);
    // A PSA 6 below raw is ordinary — a damaged slab really can be worth less.
    graded($card, 'psa-6', 500, 6.0);

    $this->actingAs($this->admin)->get('/admin/price-inversions')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));

    $this->actingAs($this->admin)->get('/admin/price-inversions?min_grade=6')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 1));
});

test('estimated values are excluded — a seeded placeholder is not an anomaly', function () {
    $card = inversionCard('Snorlax', '131');
    marketValue($card, 'NM', 3_000, ['is_estimated' => true]);
    graded($card, 'psa-9', 500, 9.0);

    $this->actingAs($this->admin)->get('/admin/price-inversions')
        ->assertInertia(fn (Assert $page) => $page->has('rows', 0));
});

test('search matches the card name, its number, and its set', function () {
    $muk = inversionCard('Muk & Alolan Muk-GX', '220');
    marketValue($muk, 'NM', 3_000);
    graded($muk, 'psa-9', 500, 9.0);

    $gengar = inversionCard('Gengar', '85');
    marketValue($gengar, 'NM', 4_000);
    graded($gengar, 'psa-9', 900, 9.0);

    $find = fn (string $q) => $this->actingAs($this->admin)
        ->get('/admin/price-inversions?q='.urlencode($q));

    $find('Gengar')->assertInertia(fn (Assert $p) => $p
        ->has('rows', 1)->where('rows.0.name', 'Gengar'));

    $find('220')->assertInertia(fn (Assert $p) => $p
        ->has('rows', 1)->where('rows.0.name', 'Muk & Alolan Muk-GX'));

    // The set name matches both cards.
    $find('Unbroken Bonds')->assertInertia(fn (Assert $p) => $p->has('rows', 2));

    $find('nothing here')->assertInertia(fn (Assert $p) => $p->has('rows', 0));
});

test('columns sort, and clicking the active one flips direction', function () {
    $small = inversionCard('Small Gap', '1');
    marketValue($small, 'NM', 1_000);
    graded($small, 'psa-9', 900, 9.0); // gap 100

    $large = inversionCard('Large Gap', '2');
    marketValue($large, 'NM', 9_000);
    graded($large, 'psa-9', 100, 9.0); // gap 8,900

    // Default: biggest shortfall first.
    $this->actingAs($this->admin)->get('/admin/price-inversions')
        ->assertInertia(fn (Assert $p) => $p->where('rows.0.name', 'Large Gap'));

    $this->actingAs($this->admin)->get('/admin/price-inversions?sort=gap&direction=asc')
        ->assertInertia(fn (Assert $p) => $p->where('rows.0.name', 'Small Gap'));

    // A different column entirely.
    $this->actingAs($this->admin)->get('/admin/price-inversions?sort=name&direction=asc')
        ->assertInertia(fn (Assert $p) => $p->where('rows.0.name', 'Large Gap'));

    $this->actingAs($this->admin)->get('/admin/price-inversions?sort=raw&direction=asc')
        ->assertInertia(fn (Assert $p) => $p->where('rows.0.name', 'Small Gap'));
});

test('an unknown sort key falls back rather than reaching the query', function () {
    $card = inversionCard('Pikachu', '58');
    marketValue($card, 'NM', 3_000);
    graded($card, 'psa-9', 500, 9.0);

    // The sort key is interpolated into raw SQL, so it must be whitelisted.
    $this->actingAs($this->admin)
        ->get('/admin/price-inversions?sort=median);DROP+TABLE+catalog_items;--')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('filters.sort', 'gap'));

    expect(CatalogItem::count())->toBe(1);
});

test('the sales floor hides inversions with too little behind them', function () {
    $thin = inversionCard('Thin Evidence', '9');
    marketValue($thin, 'NM', 3_000, ['n_sales' => 1]);
    graded($thin, 'psa-9', 500, 9.0, ['n_sales' => 1]);

    $this->actingAs($this->admin)->get('/admin/price-inversions')
        ->assertInertia(fn (Assert $p) => $p->has('rows', 1));

    $this->actingAs($this->admin)->get('/admin/price-inversions?min_sales=3')
        ->assertInertia(fn (Assert $p) => $p->has('rows', 0));
});

test('it is admin only', function () {
    $this->get('/admin/price-inversions')->assertRedirect();

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/admin/price-inversions')
        ->assertForbidden();
});
