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

it('exposes brand and series from an attached catalog item', function () {
    $line = App\Models\ProductLine::factory()->create(['name' => 'Pokémon', 'slug' => 'pokemon']);
    $set = App\Models\Set::factory()->create(['product_line_id' => $line->id, 'series' => 'Scarlet & Violet']);
    $item = App\Models\CatalogItem::factory()->create(['set_id' => $set->id, 'product_line_id' => $line->id]);

    $p = TrackedProduct::create([
        'name' => 'SV ETB',
        'catalog_item_id' => $item->id,
        'target_price' => 5000,
        'currency' => 'USD',
    ]);
    $link = $p->links()->create(['retailer' => 'walmart', 'url' => 'https://www.walmart.com/ip/x/1']);
    $link->forceFill(['last_qualified' => true, 'last_in_stock' => true, 'last_price' => 4500, 'last_checked_at' => now()])->save();

    $this->get('/deals')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('deals.0.brand', 'Pokémon')
            ->where('deals.0.brand_slug', 'pokemon')
            ->where('deals.0.series', 'Scarlet & Violet')
    );
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
            ->has('aboveMsrp', 0)
            ->where('deals.0.name', 'Surging Sparks ETB')
            ->where('deals.0.over_msrp', false)
            ->where('deals.0.offers.0.retailer', 'Walmart')
    );
});

it('lists an in-stock-above-target product under above MSRP', function () {
    $p = dealProduct(); // target 5000
    $link = $p->links()->create([
        'retailer' => 'amazon',
        'url' => 'https://www.amazon.com/dp/B000000000',
    ]);
    $link->forceFill([
        'last_qualified' => false,
        'last_in_stock' => true,
        'last_price' => 10000, // $100 vs $50 target → +100%
        'last_checked_at' => now(),
    ])->save();

    $this->get('/deals')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('deals')
            ->has('deals', 0)
            ->has('aboveMsrp', 1)
            ->where('aboveMsrp.0.over_msrp', true)
            ->where('aboveMsrp.0.offers.0.over_pct', 100)
    );
});
