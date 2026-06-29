<?php

use App\Models\TrackedProduct;
use Inertia\Testing\AssertableInertia;

function dealProduct(): TrackedProduct
{
    return TrackedProduct::create([
        'name' => 'Surging Sparks ETB',
        'target_price' => 5000,
        'currency' => 'USD',
        'is_active' => true,
    ]);
}

it('renders the public deals page', function () {
    $this->get('/deals')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('deals'));
});

it('lists a product that is currently in stock at/below target', function () {
    $p = dealProduct();
    $link = $p->links()->create([
        'retailer' => 'walmart',
        'url' => 'https://www.walmart.com/ip/x/123',
    ]);
    $link->forceFill([
        'last_qualified' => true,
        'last_in_stock' => true,
        'last_price' => 4999,
        'last_checked_at' => now(),
    ])->save();

    $this->get('/deals')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('deals')
            ->has('deals', 1)
            ->where('deals.0.name', 'Surging Sparks ETB')
            ->where('deals.0.offers.0.retailer', 'Walmart')
    );
});

it('omits products with no qualifying retailer link', function () {
    $p = dealProduct();
    $link = $p->links()->create([
        'retailer' => 'amazon',
        'url' => 'https://www.amazon.com/dp/B000000000',
    ]);
    $link->forceFill([
        'last_qualified' => false,
        'last_in_stock' => true,
        'last_price' => 9999,
        'last_checked_at' => now(),
    ])->save();

    $this->get('/deals')->assertInertia(
        fn (AssertableInertia $page) => $page->component('deals')->has('deals', 0)
    );
});
