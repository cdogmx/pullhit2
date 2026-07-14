<?php

namespace App\Support\Ebay;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use Illuminate\Support\Str;

/**
 * Fetches eBay *sold* listings for a catalog item via Oxylabs and parses them
 * into raw candidates. The query mirrors the card's display identity, e.g.
 * "Pikachu ex - 276/217 - ME: Ascended Heroes (ASC)", restricted to sold items.
 */
class EbaySoldSource
{
    public function __construct(
        protected OxylabsClient $client,
    ) {}

    /**
     * @return array<int, SoldCandidate>
     */
    public function fetch(CatalogItem $item): array
    {
        $url = $this->soldSearchUrl($item);
        $geo = config('valuation.ebay.geo', 'United States');
        $attempts = max(1, (int) config('valuation.ebay.fetch_attempts', 3));

        // eBay/Oxylabs intermittently return a valid results-page shell with NO
        // rendered listing cards (a degraded render or soft anti-bot). Those look
        // identical to a real zero, so re-fetch until we get listings — trusting
        // only an EXPLICIT "no matches" page as a genuine zero.
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $html = $this->client->fetchHtml($url, $geo);
            $candidates = EbayHtmlParser::parse($html);

            if ($candidates !== [] || EbayHtmlParser::isEmptyResults($html)) {
                return $candidates;
            }
        }

        // Every attempt came back empty without eBay declaring zero matches — a
        // block or persistent degraded render. Signal it so the caller retries
        // later instead of caching a false "no comps".
        throw new EbayBlockedException("eBay returned no usable results after {$attempts} attempt(s).");
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
     * {@see sealedSearchQuery()}). The set is left to the Language URL aspect and
     * the collector number to disambiguate — real listings rarely carry the set
     * CODE, which only added noise.
     */
    public function searchQuery(CatalogItem $item): string
    {
        if ($item->item_type === ItemType::Sealed) {
            return $this->sealedSearchQuery($item);
        }

        $parts = [];

        if ($line = $item->productLine) {
            $game = trim(Str::ascii($line->name));
            if ($game !== '') {
                $parts[] = $game;
            }
        }

        $parts[] = $item->name;

        // Pin the search to this exact printing (Reverse Holo / 1st Edition /
        // Foil / a finish or stamp) — the "variant" component.
        foreach (CardSearchTerms::qualifiers($item) as $term) {
            $parts[] = $term;
        }

        if ($item->number) {
            $parts[] = $item->number;
        }

        return implode(' - ', $parts);
    }

    /** The eBay "Language" aspect value for a card's language, or null if unknown. */
    private function ebayLanguage(CatalogItem $item): ?string
    {
        $language = $item->language ?? ($item->getAttribute('attributes')['language'] ?? null);

        return match ($language) {
            'en' => 'English',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'zh-CN', 'zh-TW' => 'Chinese',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'es' => 'Spanish',
            'pt' => 'Portuguese',
            default => null,
        };
    }

    /**
     * Sealed products sell under natural retail wording, so we search that way:
     * "{Game} - {Set} {Product}", e.g. "Pokemon - Black Bolt Booster Bundle".
     *  - The game name is prefixed (accent-stripped, so "Pokémon" → "Pokemon")
     *    to disambiguate generic product names across lines.
     *  - Both the game and set name are folded in ONLY when the product name
     *    doesn't already carry them — many sealed SKUs are named "Black Bolt
     *    Booster Bundle" (or "Disney Lorcana: …") already, and repeating just
     *    adds noise.
     *  - The set CODE is dropped: no real sealed listing title contains "(BLK)".
     */
    private function sealedSearchQuery(CatalogItem $item): string
    {
        $name = $item->name;
        $haystack = mb_strtolower($name);
        $parts = [];

        if ($line = $item->productLine) {
            $game = trim(Str::ascii($line->name));
            if ($game !== '' && ! str_contains($haystack, mb_strtolower($game))) {
                $parts[] = $game;
            }
        }

        $set = $item->set;
        if ($set && ! str_contains($haystack, mb_strtolower($set->name))) {
            $parts[] = $set->name;
        }

        $parts[] = $name;

        return implode(' - ', array_values(array_filter($parts, fn ($p) => $p !== '')));
    }
}
