<?php

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use App\Models\Set;
use Database\Seeders\GradingCompaniesSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(GradingCompaniesSeeder::class);
    $this->set = Set::factory()->create(['slug' => 'classic', 'external_ids' => ['tcgplayer_group_id' => '24837']]);

    Http::fake(['tcgcsv.com/tcgplayer/3/24837/prices' => Http::response(['results' => [
        ['productId' => 1, 'subTypeName' => 'Holofoil', 'marketPrice' => 3.98, 'midPrice' => 410.0],
        ['productId' => 2, 'subTypeName' => 'Holofoil', 'marketPrice' => 9.50],
        ['productId' => 3, 'subTypeName' => 'Holofoil', 'marketPrice' => 499.99],
        ['productId' => 4, 'subTypeName' => 'Holofoil', 'marketPrice' => 2.00],
    ]])]);
});

/** A card seeded at import from $anchor, as the pre-release imports were. */
function anchoredCard(Set $set, int $productId, int $anchorCents): CatalogItem
{
    $item = CatalogItem::factory()->create([
        'set_id' => $set->id,
        'item_type' => ItemType::Single,
        'number' => (string) $productId,
        'attributes' => ['language' => 'en', 'variant' => 'holo'],
        'external_ids' => ['tcgplayer_product_id' => $productId],
    ])->fresh();
    app(SeedSyntheticValuation::class)($item, $anchorCents);

    return $item;
}

function rawMedian(CatalogItem $item): ?int
{
    return MarketValue::where('catalog_item_id', $item->id)->where('state_key', 'NM')->value('median');
}

test('a card seeded far above its market is re-anchored on the market price', function () {
    // The live failure: Classic Collection Metagross, seeded ~$410 pre-release.
    $metagross = anchoredCard($this->set, 1, 41000);
    expect(rawMedian($metagross))->toBeGreaterThan(20000);

    $this->artisan('valuation:reanchor-synthetic')->assertSuccessful();

    expect(rawMedian($metagross))->toBeLessThan(1000)
        // $4 is no chase holo: the 1.3x synthetic PSA 9 tier goes with the anchor.
        ->and(SaleObservation::where('catalog_item_id', $metagross->id)->whereNotNull('grading_company_id')->exists())->toBeFalse();
});

test('a card near its market, or below it, is left alone', function () {
    $near = anchoredCard($this->set, 2, 1200);
    $below = anchoredCard($this->set, 3, 6900); // never raised from a thin market
    $before = [rawMedian($near), rawMedian($below)];

    $this->artisan('valuation:reanchor-synthetic')->assertSuccessful();

    expect([rawMedian($near), rawMedian($below)])->toBe($before);
});

test('a card whose real sales side with its synthetic price is kept', function () {
    $item = anchoredCard($this->set, 4, 3000);
    SaleObservation::factory()->count(3)->sequence(['price' => 2800], ['price' => 3100], ['price' => 2900])
        ->create(['catalog_item_id' => $item->id, 'is_synthetic' => false, 'observed_at' => now()->subDays(2)]);
    $synthetic = SaleObservation::where('catalog_item_id', $item->id)->where('is_synthetic', true)->pluck('price')->all();

    $this->artisan('valuation:reanchor-synthetic')->assertSuccessful();

    expect(SaleObservation::where('catalog_item_id', $item->id)->where('is_synthetic', true)->pluck('price')->all())->toBe($synthetic);
});

test('a dry run writes nothing', function () {
    $item = anchoredCard($this->set, 1, 41000);
    $before = rawMedian($item);

    $this->artisan('valuation:reanchor-synthetic --dry-run')->assertSuccessful();

    expect(rawMedian($item))->toBe($before);
});
