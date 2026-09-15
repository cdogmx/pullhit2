<?php

use App\Models\CatalogItem;
use App\Models\SaleObservation;

beforeEach(function () {
    $this->card = CatalogItem::factory()->create([
        'name' => 'Mew', 'number' => '30C',
        'attributes' => ['language' => 'en', 'variant' => 'holo', 'finish' => 'blue_rgb'],
    ]);
});

test('a dry run records nothing', function () {
    $this->artisan('valuation:add-sale', ['item' => $this->card->id, 'price' => '20000'])
        ->assertSuccessful();

    expect(SaleObservation::where('catalog_item_id', $this->card->id)->count())->toBe(0);
});

test('a hand-entered sale becomes the card value', function () {
    $this->artisan('valuation:add-sale', [
        'item' => $this->card->id, 'price' => '20000', '--execute' => true,
        '--listing' => '298674824456', '--title' => 'Ultra-Rare Blue Mew B/RGB Thirty Aniv',
    ])->assertSuccessful();

    $sale = SaleObservation::where('catalog_item_id', $this->card->id)->firstOrFail();

    expect($sale->price)->toBe(2000000)
        ->and($sale->is_synthetic)->toBeFalse()
        // Always tellable from scraped data.
        ->and($sale->raw['source'])->toBe('manual')
        ->and($this->card->marketValues()->where('state_key', 'NM')->value('n_sales'))->toBe(1);
});

test('keying on the listing id means a later scrape updates rather than duplicates', function () {
    // The sweep writes on the same key. Without it, the day the scraper finally
    // reaches this sale the card counts a $20,000 sale twice.
    foreach ([20000, 19500] as $price) {
        $this->artisan('valuation:add-sale', [
            'item' => $this->card->id, 'price' => (string) $price,
            '--execute' => true, '--listing' => '298674824456',
        ])->assertSuccessful();
    }

    expect(SaleObservation::where('catalog_item_id', $this->card->id)->count())->toBe(1)
        ->and(SaleObservation::where('catalog_item_id', $this->card->id)->value('price'))->toBe(1950000);
});

test('a real sale retires the synthetic guess for that state', function () {
    SaleObservation::create([
        'catalog_item_id' => $this->card->id, 'venue' => 'ebay', 'price' => 500,
        'currency' => 'USD', 'condition' => 'NM', 'observed_at' => now(),
        'is_synthetic' => true, 'source_listing_id' => 'seed-1', 'raw' => [],
    ]);

    $this->artisan('valuation:add-sale', [
        'item' => $this->card->id, 'price' => '20000', '--execute' => true,
    ])->assertSuccessful();

    expect(SaleObservation::where('catalog_item_id', $this->card->id)->where('is_synthetic', true)->count())
        ->toBe(0);
});

test('a price of zero is refused', function () {
    $this->artisan('valuation:add-sale', ['item' => $this->card->id, 'price' => '0', '--execute' => true])
        ->assertFailed();
});

test('an unknown card is refused', function () {
    $this->artisan('valuation:add-sale', ['item' => 999999, 'price' => '20000', '--execute' => true])
        ->assertFailed();
});
