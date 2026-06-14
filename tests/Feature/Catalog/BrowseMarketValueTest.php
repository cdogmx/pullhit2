<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use Inertia\Testing\AssertableInertia as Assert;

test('browse exposes market_value as a flat object (not data-wrapped)', function () {
    $item = CatalogItem::factory()->create(['name' => 'Marketvalue Probe']);
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM',
        'condition' => 'NM',
        'grading_company_id' => null,
        'median' => 12345,
        'n_sales' => 7,
    ]);

    // Regression: Inertia must not wrap the nested resource in a `data` key —
    // the browse tile reads items.*.market_value.median directly. (A search
    // drops smart browse into card mode.)
    $this->get('/browse?q=Marketvalue+Probe')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.market_value.median', 12345)
            ->where('items.0.market_value.n_sales', 7)
            ->missing('items.0.market_value.data'));
});
