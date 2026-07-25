<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbayBrowseClient;
use App\Support\Ebay\SealedSearch;
use App\Support\Valuation\CombinedValue;
use App\Support\Valuation\ForSaleEngine;
use App\Support\Valuation\TcgplayerLowPrice;
use Illuminate\Support\Carbon;

/**
 * Refreshes a card's "for sale" valuation from CURRENT asking prices — eBay
 * active "buy it now" listings (Browse API, free) plus the lowest TCGplayer
 * listing (TCGCSV) for ungraded. Asks per headline state — ungraded NM + PSA 10
 * for a single, the one SEALED state for a sealed product — run through
 * ForSaleEngine to get the lowest realistic ask, which is blended with the sold
 * median into the combined figure. All three live on market_values.
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

    /**
     * A sealed product's only state. It needs its own entry because the singles
     * states above are keyed to card conditions a booster box never has — which
     * is why, until this existed, no sealed product on the site had a for-sale
     * or combined figure at all. Junk is rejected by product identity rather
     * than a graded/raw regex.
     */
    private const SEALED_STATE = ['suffix' => 'Sealed', 'sealed' => true, 'tcgplayer' => true];

    public function __construct(
        protected EbayBrowseClient $browse,
        protected TcgplayerLowPrice $tcgplayer,
        protected ForSaleEngine $engine,
    ) {}

    public function __invoke(CatalogItem $item): void
    {
        $wanted = $this->statesFor($item);

        $states = $item->marketValues()
            ->whereIn('state_key', array_keys($wanted))
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
        $found = 0;

        foreach ($wanted as $stateKey => $rules) {
            $mv = $states->get($stateKey);
            if ($mv === null) {
                continue; // no sold-based row for this state — nothing to enrich
            }

            [$asks, $tcgLow] = $this->ingestAsks($item, $stateKey, $rules, $baseQuery, $limit);
            $found += count($asks);

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

        if ($found === 0) {
            $this->scheduleRetry($item);
        }
    }

    /**
     * Not one ask across any state — treat it as a blip, not an answer, and
     * back-date the stamp so the next view retries in minutes rather than hours.
     * (The stamp is still set up front, so a card that really has no listings
     * only re-checks on the short cadence, never on every view.)
     */
    protected function scheduleRetry(CatalogItem $item): void
    {
        $hours = (int) config('valuation.for_sale.view_refresh_hours', 6);
        $retry = (int) config('valuation.for_sale.empty_retry_minutes', 20);

        if ($retry >= $hours * 60) {
            return; // short window isn't shorter — nothing to back-date
        }

        $item->forceFill([
            'for_sale_refreshed_at' => Carbon::now()->subHours($hours)->addMinutes($retry),
        ])->save();
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

            // Another language's printing is a different market — never an ask.
            if (! CardSearchTerms::matchesLanguage($item, $title)) {
                continue;
            }

            // Keep eBay honest: raw states drop graded titles; PSA 10 keeps only
            // titles that actually say PSA 10 (the query alone is fuzzy). Sealed
            // has no graded axis — it's judged on product identity instead, the
            // same gates the sold comps use (lots, empties, wrong variant).
            if (($rules['sealed'] ?? false) && ! SealedSearch::matches($item, $title)) {
                continue;
            }
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

    /**
     * The priced states to look for asks on — a sealed product's single SEALED
     * state, or a single's ungraded + PSA 10 pair.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function statesFor(CatalogItem $item): array
    {
        return $item->item_type === ItemType::Sealed
            ? ['SEALED' => self::SEALED_STATE]
            : self::STATES;
    }

    protected function baseQuery(CatalogItem $item): string
    {
        // Sealed products sell under retail wording ("Pokemon - Black Bolt -
        // Booster Box"), not the card shape of name + number + set.
        if ($item->item_type === ItemType::Sealed) {
            return SealedSearch::query($item);
        }

        return trim(implode(' ', array_filter(array_merge([
            $item->name,
            $item->number,
            $item->set?->name,
        ], CardSearchTerms::qualifiers($item), [CardSearchTerms::languageKeyword($item)]))));
    }
}
