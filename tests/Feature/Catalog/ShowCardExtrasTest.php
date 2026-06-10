<?php

use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Queue::fake());

test('the card page exposes price history and null ownership for guests', function () {
    $item = CatalogItem::factory()->create();
    SaleObservation::factory()->for($item)->count(3)->create([
        'grading_company_id' => null, 'is_outlier' => false, 'observed_at' => now()->subDays(5),
    ]);

    $this->get("/catalog/{$item->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('catalog/show')
            ->where('ownership', null)
            ->has('priceHistory', 3));
});

test('an owner sees their holding in the ownership prop', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $item = CatalogItem::factory()->create();
    MarketValue::factory()->for($item)->create(['state_key' => 'NM', 'median' => 1000]);
    CollectionItem::factory()->for($user)->for($item)->create([
        'condition' => 'NM', 'grading_company_id' => null, 'grade' => null, 'quantity' => 2,
    ]);

    $this->actingAs($user)->get("/catalog/{$item->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('ownership', 1)
            ->where('ownership.0.quantity', 2)
            ->where('ownership.0.market_value', 2000));
});
