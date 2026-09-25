<?php

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\MarketValue;
use App\Models\PricechartingProduct;
use App\Models\SaleObservation;
use App\Models\Set;

test('it removes multi-card set comps and keeps the single-card ones', function () {
    $set = Set::factory()->create();
    $card = CatalogItem::factory()->create(['name' => 'Chikorita', 'number' => '46', 'set_id' => $set->id,
        'attributes' => ['language' => 'en', 'variant' => 'holo']]);
    CatalogItem::factory()->create(['name' => 'Cyndaquil', 'number' => '47', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);
    CatalogItem::factory()->create(['name' => 'Totodile', 'number' => '48', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);

    $bad = SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 9000, 'currency' => 'USD',
        'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 'a1',
        'raw' => ['title' => 'First Partner Johto Starter Set Chikorita Cyndaquil Totodile', 'source' => 'ebay'],
    ]);
    $good = SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 3000, 'currency' => 'USD',
        'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 'a2',
        'raw' => ['title' => 'Pokemon First Partners Series 2 Chikorita 046 Promo', 'source' => 'ebay'],
    ]);

    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id])->assertSuccessful();

    expect(SaleObservation::find($bad->id))->toBeNull()
        ->and(SaleObservation::find($good->id))->not->toBeNull();
});

test('a dry run removes nothing', function () {
    $card = CatalogItem::factory()->create(['name' => 'Pikachu', 'number' => '58']);
    $bad = SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 5000, 'currency' => 'USD',
        'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 'b1',
        'raw' => ['title' => 'Pokemon Lot of 50 cards Pikachu bulk', 'source' => 'ebay'],
    ]);

    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id, '--dry-run' => true])->assertSuccessful();

    expect(SaleObservation::find($bad->id))->not->toBeNull();
});

test('a card is revalued in the same pass that strips it', function () {
    // The recompute used to run once at the end, so the whole pass was only
    // correct if it ran to completion — and against production it walks 1.28M
    // comps and runs for hours. Interrupted, every card already stripped kept a
    // value derived from comps that were gone, and nothing afterwards would find
    // them: valuation:recompute --stale looks for observations NEWER than the
    // value, and a deletion leaves none.
    $set = Set::factory()->create();
    $card = CatalogItem::factory()->create(['name' => 'Chikorita', 'number' => '46', 'set_id' => $set->id,
        'attributes' => ['language' => 'en', 'variant' => 'holo']]);
    CatalogItem::factory()->create(['name' => 'Cyndaquil', 'number' => '47', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);
    CatalogItem::factory()->create(['name' => 'Totodile', 'number' => '48', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);

    foreach ([['a1', 9000, 'First Partner Johto Starter Set Chikorita Cyndaquil Totodile'],
        ['a2', 3000, 'Pokemon First Partners Series 2 Chikorita 046 Promo']] as [$id, $price, $title]) {
        SaleObservation::create([
            'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => $price, 'currency' => 'USD',
            'condition' => 'NM', 'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => $id,
            'raw' => ['title' => $title, 'source' => 'ebay'],
        ]);
    }

    // A value that still counts the bundle sale.
    MarketValue::factory()->create([
        'catalog_item_id' => $card->id, 'state_key' => 'NM',
        'grading_company_id' => null, 'median' => 6000, 'n_sales' => 2,
    ]);

    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id])->assertSuccessful();

    $value = MarketValue::where('catalog_item_id', $card->id)->where('state_key', 'NM')->first();

    // One comp left, and a value derived from it rather than from the $90
    // bundle. The exact figure is the engine's business (it nets off fees), so
    // what is pinned is that the bundle no longer counts.
    expect($value)->not->toBeNull()
        ->and($value->n_sales)->toBe(1)
        ->and($value->median)->toBeLessThan(6000);
});

test('the pass can be run in slices and resumed', function () {
    // 1.2M comps over a remote connection is not one sitting. The reject rate
    // also climbs the further back you go — 0% in September, 20.7% before
    // mid-August — so the oldest slices are the ones worth doing first.
    $set = Set::factory()->create();
    $card = CatalogItem::factory()->create(['name' => 'Chikorita', 'number' => '46', 'set_id' => $set->id,
        'attributes' => ['language' => 'en', 'variant' => 'holo']]);
    CatalogItem::factory()->create(['name' => 'Cyndaquil', 'number' => '47', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);
    CatalogItem::factory()->create(['name' => 'Totodile', 'number' => '48', 'set_id' => $set->id, 'attributes' => ['language' => 'en']]);

    $bad = collect(range(1, 4))->map(fn ($n) => SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 9000, 'currency' => 'USD',
        'condition' => 'NM', 'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => "bad{$n}",
        'raw' => ['title' => 'First Partner Johto Starter Set Chikorita Cyndaquil Totodile', 'source' => 'ebay'],
    ]));

    // Only the observations above the third id are in scope.
    $this->artisan('valuation:prune-bad-comps', [
        '--card' => $card->id, '--from-id' => $bad[1]->id,
    ])->assertSuccessful();

    expect(SaleObservation::find($bad[0]->id))->not->toBeNull()
        ->and(SaleObservation::find($bad[1]->id))->not->toBeNull()
        ->and(SaleObservation::find($bad[2]->id))->toBeNull()
        ->and(SaleObservation::find($bad[3]->id))->toBeNull();
});

test('a slice stops at its limit on a chunk boundary', function () {
    $card = CatalogItem::factory()->create(['name' => 'Pikachu', 'number' => '58']);

    SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 5000, 'currency' => 'USD',
        'condition' => 'NM', 'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 'x1',
        'raw' => ['title' => 'Lot of 50 Pokemon cards Pikachu bulk', 'source' => 'ebay'],
    ]);

    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id, '--limit' => 1])
        ->assertSuccessful();

    // Whatever it reached is still fully handled — the recompute runs per chunk.
    expect(SaleObservation::where('catalog_item_id', $card->id)->count())->toBe(0);
});

test('--price-band drops a raw comp priced far above the market', function () {
    // The Flying Pikachu V case: a clean-looking title at 23x the real price.
    // Nothing structural is wrong with it — only the price gives it away, and
    // only once the anchor stopped being the card's own broken median.
    $set = Set::factory()->create();
    $card = CatalogItem::factory()->create([
        'name' => 'Flying Pikachu V', 'number' => '6', 'set_id' => $set->id,
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
    PricechartingProduct::create([
        'pc_id' => '2618151', 'console_name' => 'Pokemon Celebrations', 'product_name' => 'Flying Pikachu V #6',
        'language' => 'en', 'set_id' => $set->id, 'card_name' => 'Flying Pikachu V', 'number' => '6',
        'is_sealed' => false, 'price_ungraded' => 428,
    ]);

    $wild = SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 10000, 'currency' => 'USD',
        'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 'w1',
        'raw' => ['title' => 'Flying Pikachu V 006/025 Celebrations Holo', 'source' => 'ebay'],
    ]);
    $fair = SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 500, 'currency' => 'USD',
        'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 'f1',
        'raw' => ['title' => 'Flying Pikachu V 006/025 Celebrations Holo NM', 'source' => 'ebay'],
    ]);

    // Without the flag the band is not consulted and both survive.
    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id])->assertSuccessful();
    expect(SaleObservation::find($wild->id))->not->toBeNull();

    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id, '--price-band' => true])
        ->assertSuccessful();

    expect(SaleObservation::find($wild->id))->toBeNull()
        ->and(SaleObservation::find($fair->id))->not->toBeNull();
});

test('--price-band leaves a legitimately expensive graded comp alone', function () {
    // A PSA 10 sells for many times the raw price. The band applies only to
    // comps that resolve to a raw state, and forgetting that would delete
    // every high-grade slab we hold.
    $set = Set::factory()->create();
    $card = CatalogItem::factory()->create([
        'name' => 'Flying Pikachu V', 'number' => '6', 'set_id' => $set->id,
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
    GradingCompany::factory()->create(['slug' => 'psa', 'name' => 'PSA']);
    PricechartingProduct::create([
        'pc_id' => '2618151b', 'console_name' => 'Pokemon Celebrations', 'product_name' => 'Flying Pikachu V #6',
        'language' => 'en', 'set_id' => $set->id, 'card_name' => 'Flying Pikachu V', 'number' => '6',
        'is_sealed' => false, 'price_ungraded' => 428,
    ]);

    $slab = SaleObservation::create([
        'catalog_item_id' => $card->id, 'venue' => 'ebay', 'price' => 21000, 'currency' => 'USD',
        'observed_at' => now(), 'is_synthetic' => false, 'source_listing_id' => 's1',
        'raw' => ['title' => 'Pokemon Celebrations Flying Pikachu V 006/025 PSA 10', 'source' => 'ebay'],
    ]);

    $this->artisan('valuation:prune-bad-comps', ['--card' => $card->id, '--price-band' => true])
        ->assertSuccessful();

    expect(SaleObservation::find($slab->id))->not->toBeNull();
});
