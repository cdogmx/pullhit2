<?php

namespace App\Support\Ebay;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Fetches eBay *sold* listings for a catalog item via Oxylabs and parses them
 * into raw candidates. The query mirrors the card's display identity, e.g.
 * "Pikachu ex - 276/217 - ME: Ascended Heroes (ASC)", restricted to sold items.
 *
 * Sold searches can be gated in a way active searches are not — any of
 * LH_Sold/LH_Complete can bounce to eBay's captcha splash or a sign-in wall
 * while the same keywords unfiltered come back whole. When that happens every
 * request is a paid call that cannot succeed, and the caller's retry-on-next-
 * view policy turns a standing gate into an open tap. So consecutive blocks
 * trip a breaker: the source reports itself down without spending, and tries
 * again after a cooldown so it recovers on its own.
 */
class EbaySoldSource
{
    /** Set while sold search is presumed gated; its TTL is the cooldown. */
    private const DOWN_KEY = 'ebay:sold:gated';

    /** Consecutive blocked fetches since the last readable page. */
    private const STRIKES_KEY = 'ebay:sold:strikes';

    public function __construct(
        protected OxylabsClient $client,
    ) {}

    /**
     * @return array<int, SoldCandidate>
     */
    public function fetch(CatalogItem $item): array
    {
        // Checked before the breaker so being switched off never looks like a
        // gate, and never leaves strikes behind for the next run to inherit.
        if (! config('valuation.ebay.enabled')) {
            throw new EbayDisabledException('eBay fetching is switched off (EBAY_REFRESH_ENABLED=false).');
        }

        if ($this->isDown()) {
            throw new EbayBlockedException('eBay sold search is gated; not retrying until the cooldown lapses.');
        }

        $url = $this->soldSearchUrl($item);
        $geo = config('valuation.ebay.geo', 'United States');
        $attempts = max(1, (int) config('valuation.ebay.fetch_attempts', 3));

        // eBay/Oxylabs intermittently return a valid results-page shell with NO
        // rendered listing cards (a degraded render or soft anti-bot). Those look
        // identical to a real zero, so re-fetch until we get listings — trusting
        // only an EXPLICIT "no matches" page as a genuine zero.
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $html = $this->client->fetchHtml($url, $geo, budget: OxylabsClient::BUDGET_EBAY);
            $candidates = EbayHtmlParser::parse($html);

            if ($candidates !== [] || EbayHtmlParser::isEmptyResults($html)) {
                // A page we could read at all means the gate is not up.
                $this->recordReachable();

                return $candidates;
            }
        }

        $this->recordBlocked();

        // Every attempt came back empty without eBay declaring zero matches — a
        // block or persistent degraded render. Signal it so the caller retries
        // later instead of caching a false "no comps".
        throw new EbayBlockedException("eBay returned no usable results after {$attempts} attempt(s).");
    }

    /** Whether the breaker is open, i.e. sold search is presumed gated. */
    public function isDown(): bool
    {
        return Cache::get(self::DOWN_KEY) !== null;
    }

    /** How many consecutive blocks have been seen since the last readable page. */
    public function consecutiveBlocks(): int
    {
        return (int) Cache::get(self::STRIKES_KEY, 0);
    }

    /** Close the breaker — used by the CLI to force a retry before the cooldown. */
    public function reset(): void
    {
        Cache::forget(self::DOWN_KEY);
        Cache::forget(self::STRIKES_KEY);
    }

    /**
     * A page came back readable, so whatever the gate was, it is not up now.
     */
    private function recordReachable(): void
    {
        if ($this->consecutiveBlocks() > 0 || $this->isDown()) {
            $this->reset();
        }
    }

    /**
     * Another fetch produced nothing eBay was willing to call an empty result.
     * Past the threshold the source stops spending until the cooldown lapses.
     */
    private function recordBlocked(): void
    {
        $strikes = $this->consecutiveBlocks() + 1;
        $threshold = max(1, (int) config('valuation.ebay.breaker.threshold', 4));
        $cooldown = max(1, (int) config('valuation.ebay.breaker.cooldown_minutes', 60));

        // Strikes outlive the cooldown so a gate that is still up on the probe
        // trips again immediately rather than spending another full threshold.
        Cache::put(self::STRIKES_KEY, $strikes, now()->addMinutes($cooldown * 4));

        if ($strikes >= $threshold) {
            Cache::put(self::DOWN_KEY, now()->toIso8601String(), now()->addMinutes($cooldown));
        }
    }

    public function soldSearchUrl(CatalogItem $item): string
    {
        $params = [
            '_nkw' => $this->searchQuery($item),
            '_sacat' => 0,
            'LH_Sold' => 1,
            'LH_Complete' => 1,
            '_ipg' => config('valuation.ebay.max_results', 60),
        ];

        // Ship-to US postal code so eBay ranks/estimates from a domestic buyer's
        // vantage (matches the geo we scrape from). Configurable; default US ZIP.
        if ($postal = config('valuation.ebay.postal', '53094')) {
            $params['_stpos'] = $postal;
        }

        // Filter to the card's language (eBay "Language" item aspect) so an
        // English card's comps aren't polluted by Japanese/Chinese printings and
        // vice-versa. Omitted when the language is unknown (don't over-restrict).
        if ($language = $this->ebayLanguage($item)) {
            $params['Language'] = $language;
        }

        return 'https://www.ebay.com/sch/i.html?'.http_build_query($params);
    }

    /**
     * The eBay keyword string for a catalog item. Singles use
     * "{Game} - {Name} - {Variant} - {Number}" (e.g. "Pokemon - Snivy - Reverse
     * Holo - 1"); sealed products use the natural retail wording instead (see
     * {@see SealedSearch::query()}). The set is left to the Language URL aspect
     * and the collector number to disambiguate — real listings rarely carry the
     * set CODE, which only added noise.
     */
    public function searchQuery(CatalogItem $item): string
    {
        if ($item->item_type === ItemType::Sealed) {
            return SealedSearch::query($item);
        }

        // A colourway print gets its own, much shorter query — the sellers of
        // these agree on the card's name and on "RGB", and on nothing else.
        if ($colourway = CardSearchTerms::colourwayQuery($item)) {
            return $colourway;
        }

        $parts = [];

        if ($line = $item->productLine) {
            $game = trim(Str::ascii($line->name));
            if ($game !== '') {
                $parts[] = $game;
            }
        }

        // Brand, set, card, number — widest scope to narrowest.
        if ($set = CardSearchTerms::setTerm($item)) {
            $parts[] = $set;
        }

        // The card's name as a seller writes it, without the set we bracket onto
        // it to tell two printings apart in our own catalog.
        $parts[] = CardSearchTerms::cardTerm($item);

        // Pin the search to this exact printing (Reverse Holo / 1st Edition /
        // Foil / a finish or stamp) — the "variant" component. It qualifies the
        // card, so it sits with the card rather than ahead of the number.
        foreach (CardSearchTerms::qualifiers($item) as $term) {
            $parts[] = $term;
        }

        if ($number = CardSearchTerms::numberTerm($item)) {
            $parts[] = $number;
        }

        // Joined with spaces, not " - ". eBay reads a leading minus as an
        // exclusion operator, so a separator dash sitting against the next word
        // risks the search excluding the very terms we added to narrow it.
        return implode(' ', $parts);
    }

    /** The eBay "Language" aspect value for a card's language, or null if unknown. */
    private function ebayLanguage(CatalogItem $item): ?string
    {
        return CardSearchTerms::language($item);
    }
}
