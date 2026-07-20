<?php

use App\Actions\Valuation\IngestForSaleListings;
use App\Models\CatalogItem;
use App\Models\ListingObservation;
use App\Models\MarketValue;
use App\Support\Ebay\EbayBrowseClient;
use App\Support\Valuation\TcgplayerLowPrice;

/** A Browse client returning canned active listings per query. */
function fakeBrowse(array $byMatch): void
{
    app()->instance(EbayBrowseClient::class, new class($byMatch) extends EbayBrowseClient
    {
        public function __construct(private array $byMatch) {}

        public function search(string $query, int $limit = 6): array
        {
            foreach ($this->byMatch as $needle => $listings) {
                if (str_contains($query, $needle)) {
                    return $listings;
                }
            }

            return [];
        }
    });
}

function listing(int $cents, string $title): array
{
    return ['title' => $title, 'price_cents' => $cents, 'currency' => 'USD', 'condition' => null, 'url' => "https://ebay/{$cents}"];
}

beforeEach(function () {
    // No TCGplayer product by default -> TCGCSV source is skipped.
    app()->instance(TcgplayerLowPrice::class, new class extends TcgplayerLowPrice
    {
        public function __construct() {}

        public function forItem(CatalogItem $item): ?int
        {
            return null;
        }
    });

    $this->item = CatalogItem::factory()->create(['name' => 'Mega Darkrai ex', 'number' => '116']);
    $this->nm = MarketValue::factory()->for($this->item)->create([
        'state_key' => 'NM', 'condition' => 'NM', 'grading_company_id' => null,
        'median' => 32000, 'n_sales' => 200,
    ]);
});

test('it computes a for-sale value from eBay asks and blends the combined figure', function () {
    fakeBrowse(['Near Mint' => [
        listing(30500, 'Mega Darkrai ex 116 Near Mint'),
        listing(31000, 'Mega Darkrai ex 116 NM'),
        listing(33000, 'Mega Darkrai ex 116'),
        listing(34900, 'Mega Darkrai ex 116 mint'),
    ]]);

    app(IngestForSaleListings::class)($this->item);

    $nm = $this->nm->fresh();
    expect($nm->for_sale)->not->toBeNull()
        ->and($nm->for_sale)->toBeGreaterThanOrEqual(30500)
        ->and($nm->for_sale)->toBeLessThan(32000)     // below sold -> a real ask floor
        ->and($nm->for_sale_n)->toBe(4)
        ->and($nm->combined)->toBeLessThan($nm->median) // combined pulled down toward asks
        ->and($nm->combined)->toBeGreaterThan($nm->for_sale);

    // The asks were stored as listing_observations for transparency.
    expect(ListingObservation::where('catalog_item_id', $this->item->id)->where('state_key', 'NM')->count())->toBe(4);
});

test('graded listings are excluded from the ungraded NM asks', function () {
    fakeBrowse(['Near Mint' => [
        listing(30000, 'Mega Darkrai ex 116 Near Mint'),
        listing(31000, 'Mega Darkrai ex 116 NM'),
        listing(95000, 'Mega Darkrai ex 116 PSA 10 GEM MINT'), // graded — must be dropped
        listing(88000, 'Mega Darkrai ex 116 CGC 10'),          // graded — must be dropped
    ]]);

    app(IngestForSaleListings::class)($this->item);

    // Only the two raw asks survive; the graded ones never entered the pool.
    expect($this->nm->fresh()->for_sale_n)->toBe(2)
        ->and($this->nm->fresh()->for_sale)->toBeLessThan(35000)
        ->and(ListingObservation::where('catalog_item_id', $this->item->id)->count())->toBe(2);
});

test('the TCGplayer low is included as an ask for the ungraded state', function () {
    // Swap in a TCGplayer source that returns a low ask under the eBay ones.
    app()->instance(TcgplayerLowPrice::class, new class extends TcgplayerLowPrice
    {
        public function __construct() {}

        public function forItem(CatalogItem $item): ?int
        {
            return 29500;
        }
    });

    fakeBrowse(['Near Mint' => [
        listing(31000, 'Mega Darkrai ex 116 NM'),
        listing(33000, 'Mega Darkrai ex 116'),
    ]]);

    app(IngestForSaleListings::class)($this->item);

    expect(ListingObservation::where('catalog_item_id', $this->item->id)->where('venue', 'tcgplayer')->count())->toBe(1)
        ->and($this->nm->fresh()->for_sale_n)->toBe(3); // 2 eBay + 1 TCGplayer
});

test('the TCGplayer low alone anchors for-sale when eBay has no asks', function () {
    // eBay Browse unconfigured / empty (the state until credentials are set),
    // and TCGplayer gives its vetted lowest listing — trust it even as a single.
    app()->instance(TcgplayerLowPrice::class, new class extends TcgplayerLowPrice
    {
        public function __construct() {}

        public function forItem(CatalogItem $item): ?int
        {
            return 27000;
        }
    });
    fakeBrowse([]); // no eBay listings

    app(IngestForSaleListings::class)($this->item);

    $nm = $this->nm->fresh();
    expect($nm->for_sale)->toBe(27000)   // the TCGplayer low, standing alone
        ->and($nm->for_sale_n)->toBe(1)
        ->and($nm->combined)->toBe(29500); // 32000 + (27000-32000)*0.5, asks below sold
});

test('a stale refresh timestamp is written even when no asks are found', function () {
    fakeBrowse([]); // no listings for any query

    app(IngestForSaleListings::class)($this->item);

    expect($this->item->fresh()->for_sale_refreshed_at)->not->toBeNull()
        ->and($this->nm->fresh()->for_sale)->toBeNull()
        ->and($this->nm->fresh()->combined)->toBe($this->nm->median); // combined falls back to sold
});
