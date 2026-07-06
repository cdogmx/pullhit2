<?php

use App\Actions\Collection\AddToCollection;
use App\Actions\Collection\BuildPortfolio;
use App\Enums\Condition;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    $this->item = CatalogItem::factory()->create();

    // Two priced states for the same card.
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => Condition::NearMint, 'median' => 1000,
    ]);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'psa-10', 'condition' => null,
        'grading_company_id' => $this->psa->id, 'grade' => 10.0, 'median' => 50000,
    ]);

    $add = app(AddToCollection::class);
    $add($this->user, $this->item, ['condition' => 'NM', 'quantity' => 2, 'unit_cost' => 800]); // value 2000, cost 1600
    $add($this->user, $this->item, [
        'grading_company_id' => $this->psa->id, 'grade' => 10, 'quantity' => 1, 'unit_cost' => 40000,
    ]); // value 50000, cost 40000
});

test('a holding resolves its value from the matching priced state', function () {
    $nm = $this->user->collectionItems()->whereNotNull('condition')->first();
    $graded = $this->user->collectionItems()->whereNotNull('grading_company_id')->first();

    expect($nm->currentUnitValue())->toBe(1000)
        ->and($graded->currentUnitValue())->toBe(50000); // graded ≠ raw
});

test('BuildPortfolio totals value, cost basis, and unrealized P&L', function () {
    $portfolio = app(BuildPortfolio::class)($this->user);
    $s = $portfolio['summary'];

    expect($s['total_value'])->toBe(52000)      // 2000 + 50000
        ->and($s['total_cost'])->toBe(41600)     // 1600 + 40000
        ->and($s['unrealized_gain'])->toBe(10400)
        ->and($s['card_count'])->toBe(3)
        ->and($s['item_count'])->toBe(2)
        ->and($portfolio['gainers'])->toHaveCount(2);

    // Movers + allocation carry the fields the dashboard links with.
    expect($portfolio['gainers'][0])->toHaveKey('url')
        ->and($portfolio['gainers'][0]['url'])->toBeString()
        ->and($portfolio['allocation'][0])->toHaveKeys(['brand_slug', 'set_slug']);
});

test('a held state with no market value yields a null value, not a crash', function () {
    $other = CatalogItem::factory()->create();
    app(AddToCollection::class)($this->user, $other, ['condition' => 'NM', 'quantity' => 1, 'unit_cost' => 500]);

    $holding = $this->user->collectionItems()->where('catalog_item_id', $other->id)->first();
    expect($holding->currentUnitValue())->toBeNull();

    // The unvalued card doesn't inflate totals.
    $s = app(BuildPortfolio::class)($this->user)['summary'];
    expect($s['total_value'])->toBe(52000)
        ->and($s['valued_count'])->toBe(2);
});

test('the API returns the portfolio for the authenticated user', function () {
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/collection')
        ->assertOk()
        ->assertJsonPath('summary.total_value', 52000)
        ->assertJsonCount(2, 'holdings');
});

test('the collection page requires authentication', function () {
    $this->get('/collection')->assertRedirect('/login');
});

test('an authenticated user can view their collection page', function () {
    $this->actingAs($this->user)->get('/collection')->assertOk();
});
