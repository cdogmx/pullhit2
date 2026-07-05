<?php

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\TrackedProduct;
use App\Models\Vertical;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $vertical = Vertical::factory()->create(['slug' => 'tcg']);
    $this->line = ProductLine::factory()->for($vertical)->create(['slug' => 'pokemon']);
    $this->set = Set::factory()->for($this->line)->create(['slug' => 'surging-sparks']);
    $this->item = CatalogItem::factory()->sealed()->create([
        'product_line_id' => $this->line->id,
        'set_id' => $this->set->id,
        'slug' => 'surging-sparks-booster-box',
    ]);
});

/** Attach a retailer link (with polled state) to the item via a tracked product. */
function trackLink(CatalogItem $item, string $retailer, array $state, bool $active = true): void
{
    $product = TrackedProduct::create([
        'catalog_item_id' => $item->id,
        'name' => $item->name,
        'target_price' => 16164,
        'currency' => 'USD',
        'is_active' => true,
    ]);

    $link = $product->links()->create([
        'retailer' => $retailer,
        'url' => "https://{$retailer}.example/x",
        'is_active' => $active,
    ]);

    $link->forceFill($state)->save();
}

test('the item page surfaces live deals-tracker offers, in-stock and cheapest first', function () {
    trackLink($this->item, 'target', ['last_in_stock' => false, 'last_price' => 13999, 'last_checked_at' => now()]);
    trackLink($this->item, 'walmart', ['last_in_stock' => true, 'last_price' => 15999, 'last_checked_at' => now()]);
    trackLink($this->item, 'costco', ['last_in_stock' => true, 'last_price' => 14999, 'last_checked_at' => now()]);

    $this->get('/pokemon/surging-sparks/surging-sparks-booster-box')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('whereToBuy', 3)
            // In-stock first (Costco 149.99 < Walmart 159.99), then out-of-stock Target.
            ->where('whereToBuy.0.retailer', 'Costco')
            ->where('whereToBuy.0.in_stock', true)
            ->where('whereToBuy.0.price_cents', 14999)
            ->where('whereToBuy.1.retailer', 'Walmart')
            ->where('whereToBuy.2.retailer', 'Target')
            ->where('whereToBuy.2.in_stock', false));
});

test('inactive links and inactive tracked products are excluded', function () {
    trackLink($this->item, 'target', ['last_in_stock' => true, 'last_price' => 15999, 'last_checked_at' => now()], active: false);

    $this->get('/pokemon/surging-sparks/surging-sparks-booster-box')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('whereToBuy', 0));
});
