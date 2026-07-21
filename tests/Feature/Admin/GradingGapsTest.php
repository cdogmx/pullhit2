<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    $this->psa = GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
});

/** A card with a real NM value and a real PSA-10 value. */
function gapCard(int $nm, int $psa10, int $psaN = 5): CatalogItem
{
    $item = CatalogItem::factory()->create();
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null,
        'median' => $nm, 'n_sales' => 20, 'is_estimated' => false,
    ]);
    MarketValue::factory()->for($item)->create([
        'state_key' => 'psa-10', 'condition' => null,
        'grading_company_id' => test()->psa->id, 'grade' => 10,
        'median' => $psa10, 'n_sales' => $psaN, 'is_estimated' => false,
    ]);

    return $item;
}

test('non-admins cannot reach the grading-gaps page', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user)->get('/admin/grading-gaps')->assertForbidden();
});

test('it lists cards whose PSA 10 value clears the raw price plus the grading fee', function () {
    $winner = gapCard(nm: 1000, psa10: 8500);   // profit $85 - $25 fee = well positive
    gapCard(nm: 1000, psa10: 1500);             // +$5 gap < $25 fee — excluded

    $this->actingAs($this->admin)
        ->get('/admin/grading-gaps?fee=25')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p
            ->component('admin/grading-gaps')
            ->has('rows', 1)
            ->where('rows.0.id', $winner->id)
            ->where('rows.0.nm', 1000)
            ->where('rows.0.psa10', 8500)
            ->where('rows.0.profit', 8500 - 1000 - 2500) // psa10 - nm - fee
            ->where('rows.0.multiple', 8.5));
});

test('estimated values on either side are excluded', function () {
    $item = CatalogItem::factory()->create();
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 1000, 'n_sales' => 20, 'is_estimated' => false,
    ]);
    // PSA-10 is only an estimate — no real graded sales — so it must not appear.
    MarketValue::factory()->for($item)->create([
        'state_key' => 'psa-10', 'grading_company_id' => $this->psa->id, 'grade' => 10,
        'median' => 9000, 'n_sales' => 5, 'is_estimated' => true,
    ]);

    $this->actingAs($this->admin)
        ->get('/admin/grading-gaps')
        ->assertInertia(fn (Assert $p) => $p->has('rows', 0));
});

test('a thin graded market is filtered by the min PSA 10 sales floor', function () {
    gapCard(nm: 1000, psa10: 8000, psaN: 1); // only one graded sale

    $this->actingAs($this->admin)
        ->get('/admin/grading-gaps?min_graded_sales=2')
        ->assertInertia(fn (Assert $p) => $p->has('rows', 0));
});

test('the min NM value floor drops bulk cards', function () {
    gapCard(nm: 50, psa10: 8000); // $0.50 raw — bulk

    $this->actingAs($this->admin)
        ->get('/admin/grading-gaps?min_value=5') // $5 floor
        ->assertInertia(fn (Assert $p) => $p->has('rows', 0));
});
