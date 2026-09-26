<?php

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;

/**
 * Values left behind by a pass that deleted comps.
 *
 * Deleting rows does not make the survivors newer, so --stale cannot see this:
 * it looks for an observation more recent than the value, and a deletion leaves
 * none. The card keeps a price derived from sales it no longer holds, quietly,
 * for as long as nobody adds a new comp to it.
 */
function orphanedCard(int $claimedSales, array $prices): CatalogItem
{
    $item = CatalogItem::factory()->create([
        'name' => 'Shaymin EX', 'number' => '77a',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);

    foreach ($prices as $i => $price) {
        SaleObservation::factory()->for($item)->create([
            'condition' => 'NM', 'grading_company_id' => null, 'grade' => null,
            'price' => $price, 'is_synthetic' => false,
            'raw' => ['title' => 'Shaymin EX 77a/108 Roaring Skies', 'source' => 'ebay'],
        ]);
    }

    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => 'NM',
        'median' => 581, 'n_sales' => $claimedSales, 'is_estimated' => false,
    ]);

    return $item;
}

test('--orphaned finds a value counting sales the card no longer has', function () {
    // The live case: $5.81 on 27 counted sales when 14 remained, every one of
    // them over $130. The cheap ones had been pruned and nothing recomputed.
    $item = orphanedCard(27, [23000, 25000, 30000]);

    $this->artisan('valuation:recompute', ['--orphaned' => true])->assertSuccessful();

    $value = MarketValue::where('catalog_item_id', $item->id)->where('state_key', 'NM')->first();

    expect($value->n_sales)->toBe(3)
        ->and($value->median)->toBeGreaterThan(20000);
});

test('it leaves a card whose count already matches', function () {
    $item = orphanedCard(3, [23000, 25000, 30000]);
    MarketValue::where('catalog_item_id', $item->id)->update(['median' => 25000]);

    $this->artisan('valuation:recompute', ['--orphaned' => true])
        ->expectsOutputToContain('Nothing to recompute')
        ->assertSuccessful();
});

test('--stale does not find it, which is why --orphaned exists', function () {
    // Pinning the gap rather than describing it: if --stale ever did catch
    // this, --orphaned would be redundant and should go.
    orphanedCard(27, [23000, 25000, 30000]);

    $this->artisan('valuation:recompute', ['--stale' => true])
        ->expectsOutputToContain('Nothing to recompute')
        ->assertSuccessful();
});

test('a card whose comps were all pruned stops showing a price', function () {
    // 441 cards were displaying an NM price with nothing behind it at all.
    // They were invisible to the recompute because it only looked at cards that
    // still HAD observations — which is precisely the set this excludes.
    $item = CatalogItem::factory()->create([
        'name' => 'Ghost Price', 'number' => '1',
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
    ]);
    MarketValue::factory()->for($item)->create([
        'state_key' => 'NM', 'condition' => 'NM',
        'median' => 4500, 'n_sales' => 6, 'is_estimated' => false,
    ]);

    $this->artisan('valuation:recompute', ['--orphaned' => true])->assertSuccessful();

    expect(MarketValue::where('catalog_item_id', $item->id)->count())->toBe(0);
});
