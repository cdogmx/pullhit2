<?php

use App\Models\CatalogItem;
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
