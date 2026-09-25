<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\PricechartingProduct;
use App\Models\Set;
use App\Support\Valuation\RawAnchor;

/**
 * The anchor decides which raw comps are believable, so where it comes from
 * decides whether a wrong value can defend itself.
 *
 * Anchoring on the card's own raw median made the check circular: Celebrations
 * Flying Pikachu V held $53.35 against a real market of $4.28, and because the
 * band is 0.1–5x the anchor, that wrong number raised the ceiling to $267 and
 * waved through the next expensive listing. PriceCharting's ungraded price is
 * independent of anything we computed, so it cannot be moved by our own bad
 * comps — which is the whole point of using it.
 */
beforeEach(function () {
    $this->set = Set::factory()->create();
    $this->item = CatalogItem::factory()->create([
        'set_id' => $this->set->id,
        'name' => 'Flying Pikachu V',
        'number' => '6',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
    $this->anchor = app(RawAnchor::class);
});

function anchorPcRow(Set $set, array $overrides = []): PricechartingProduct
{
    return PricechartingProduct::create(array_merge([
        'pc_id' => (string) mt_rand(1, 999999),
        'console_name' => 'Pokemon Celebrations',
        'product_name' => 'Flying Pikachu V #6',
        'language' => 'en',
        'set_id' => $set->id,
        'card_name' => 'Flying Pikachu V',
        'number' => '6',
        'is_sealed' => false,
        'price_ungraded' => 428,
    ], $overrides));
}

test('it prefers PriceCharting ungraded over our own median', function () {
    anchorPcRow($this->set);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 5335, 'is_estimated' => false,
    ]);

    // The exact case from the live card: our own number is 12x the market.
    expect($this->anchor->for($this->item))->toBe(428);
});

test('it falls back to our own median when PriceCharting has no row', function () {
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 5335, 'is_estimated' => false,
    ]);

    // Most of the catalog is not on PriceCharting. Those cards must keep
    // working exactly as before rather than losing their band entirely.
    expect($this->anchor->for($this->item))->toBe(5335);
});

test('it ignores a PriceCharting row with no ungraded price', function () {
    anchorPcRow($this->set, ['price_ungraded' => null]);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 5335, 'is_estimated' => false,
    ]);

    expect($this->anchor->for($this->item))->toBe(5335);
});

test('it does not borrow the price of a different card in the same set', function () {
    // Number and name both have to agree. Matching on number alone would hand
    // Flying Pikachu V the price of whatever else is printed at #6.
    anchorPcRow($this->set, ['card_name' => 'Surfing Pikachu V', 'number' => '7']);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 5335, 'is_estimated' => false,
    ]);

    expect($this->anchor->for($this->item))->toBe(5335);
});

test('it matches numbers written differently', function () {
    // PriceCharting writes 6, we may hold 006/025 — the same card.
    anchorPcRow($this->set, ['number' => '006']);
    $this->item->forceFill(['number' => '006/025'])->save();

    expect($this->anchor->for($this->item))->toBe(428);
});

test('it never anchors a single on a sealed product', function () {
    anchorPcRow($this->set, ['is_sealed' => true, 'price_ungraded' => 12000]);
    MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'median' => 5335, 'is_estimated' => false,
    ]);

    // A booster box at #6 would raise the ceiling rather than lower it.
    expect($this->anchor->for($this->item))->toBe(5335);
});

test('it returns zero when there is nothing to anchor on', function () {
    // Which the band reads as "no opinion" and accepts anything — the
    // bootstrapping hole, unchanged here and worth closing separately.
    expect($this->anchor->for($this->item))->toBe(0);
});
