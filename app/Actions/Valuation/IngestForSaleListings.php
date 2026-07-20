<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbayBrowseClient;
use App\Support\Valuation\CombinedValue;
use App\Support\Valuation\ForSaleEngine;
use App\Support\Valuation\TcgplayerLowPrice;
use Illuminate\Support\Carbon;

/**
 * Refreshes a card's "for sale" valuation from CURRENT asking prices — eBay
 * active "buy it now" listings (Browse API, free) plus the lowest TCGplayer
 * listing (TCGCSV) for ungraded. Asks per headline state (ungraded NM + PSA 10)
 * run through ForSaleEngine to get the lowest realistic ask, which is blended
 * with the sold median into the combined figure. All three live on market_values.
 *
 * Asks are ephemeral, so each run replaces the card's listing_observations. Only
 * states that already have a (sold-based) market_values row are enriched — the
 * for-sale value supplements the sold one, it doesn't create bare rows.
 */
class IngestForSaleListings
{
    /** Headline states we price for-sale, with how to fetch + filter their asks. */
    private const STATES = [
        'NM' => ['suffix' => 'Near Mint', 'exclude' => '/\b(psa|bgs|cgc|sgc|graded|gem\s*mint)\b/i', 'tcgplayer' => true],
        'psa-10' => ['suffix' => 'PSA 10', 'require' => '/\bpsa\s*10\b/i', 'tcgplayer' => false],
    ];

    public function __construct(
        protected EbayBrowseClient $browse,
        protected TcgplayerLowPrice $tcgplayer,
        protected ForSaleEngine $engine,
    ) {}

    public function __invoke(CatalogItem $item): void
    {
        $states = $item->marketValues()
            ->whereIn('state_key', array_keys(self::STATES))
            ->get()
            ->keyBy('state_key');

        // Always record the attempt so the TTL is respected even on a dry result.
        $item->forceFill(['for_sale_refreshed_at' => Carbon::now()])->save();

        if ($states->isEmpty()) {
            return;
        }

        $item->listingObservations()->delete(); // replace — asks are ephemeral

        $limit = (int) config('valuation.for_sale.ebay_limit', 20);
        $baseQuery = $this->baseQuery($item);

        foreach (self::STATES as $stateKey => $rules) {
            $mv = $states->get($stateKey);
            if ($mv === null) {
                continue; // no sold-based row for this state — nothing to enrich
            }

            [$asks, $tcgLow] = $this->ingestAsks($item, $stateKey, $rules, $baseQuery, $limit);

            // The engine needs a distribution (>= min_asks) to filter junk. When
            // it can't run but TCGplayer gave us its lowest listing — already the
            // vetted market floor — trust that single figure rather than show
            // nothing (the common case until eBay Browse credentials are set).
            $result = $this->engine->value($asks);
            $forSale = $result['for_sale'] ?? $tcgLow;
            $forSaleN = $result['n'] ?? ($tcgLow !== null ? max(1, count($asks)) : null);

            $mv->update([
                'for_sale' => $forSale,
                'for_sale_n' => $forSaleN,
                'combined' => CombinedValue::blend($mv->median, $forSale),
            ]);
        }
    }

    /**
     * Fetch + store this state's asks (eBay + optional TCGplayer), returning the
     * ask prices in cents plus the TCGplayer low on its own (null if none) so the
     * caller can trust it as a floor when the eBay pool is too thin to filter.
     *
     * @param  array<string, mixed>  $rules
     * @return array{0: array<int, int>, 1: int|null}
     */
    protected function ingestAsks(CatalogItem $item, string $stateKey, array $rules, string $baseQuery, int $limit): array
    {
        $now = Carbon::now();
        $asks = [];
        $rows = [];

        $query = trim($baseQuery.' '.$rules['suffix']);
        foreach ($this->browse->search($query, $limit) as $listing) {
            $title = (string) ($listing['title'] ?? '');

            // Keep eBay honest: raw states drop graded titles; PSA 10 keeps only
            // titles that actually say PSA 10 (the query alone is fuzzy).
            if (isset($rules['exclude']) && preg_match($rules['exclude'], $title)) {
                continue;
            }
            if (isset($rules['require']) && ! preg_match($rules['require'], $title)) {
                continue;
            }

            $price = (int) ($listing['price_cents'] ?? 0);
            if ($price <= 0) {
                continue;
            }

            $asks[] = $price;
            $rows[] = [
                'state_key' => $stateKey,
                'venue' => 'ebay',
                'price' => $price,
                'currency' => $listing['currency'] ?? 'USD',
                'url' => $listing['url'] ?? null,
                'observed_at' => $now,
                'raw' => ['title' => $title, 'condition' => $listing['condition'] ?? null],
            ];
        }

        // TCGplayer's lowest listing (ungraded only — no graded market there).
        $tcgLow = null;
        if (($rules['tcgplayer'] ?? false) && ($low = $this->tcgplayer->forItem($item)) !== null) {
            $tcgLow = $low;
            $asks[] = $low;
            $rows[] = [
                'state_key' => $stateKey,
                'venue' => 'tcgplayer',
                'price' => $low,
                'currency' => 'USD',
                'source_listing_id' => 'tcgcsv:'.($item->external_ids['tcgplayer_product_id'] ?? ''),
                'observed_at' => $now,
                'raw' => ['source' => 'tcgcsv_low'],
            ];
        }

        if ($rows !== []) {
            $item->listingObservations()->createMany($rows);
        }

        return [$asks, $tcgLow];
    }

    protected function baseQuery(CatalogItem $item): string
    {
        return trim(implode(' ', array_filter(array_merge([
            $item->name,
            $item->number,
            $item->set?->name,
        ], CardSearchTerms::qualifiers($item)))));
    }
}
