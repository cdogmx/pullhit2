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
        $html = $this->client->fetchHtml(
            $this->soldSearchUrl($item),
            config('valuation.ebay.geo', 'United States'),
        );

        return EbayHtmlParser::parse($html);
    }

    public function soldSearchUrl(CatalogItem $item): string
    {
        $query = $this->searchQuery($item);

        return 'https://www.ebay.com/sch/i.html?'.http_build_query([
            '_nkw' => $query,
            '_sacat' => 0,
            'LH_Sold' => 1,
            'LH_Complete' => 1,
            '_ipg' => config('valuation.ebay.max_results', 60),
        ]);
    }

    /**
     * The eBay keyword string for a catalog item. Singles use the card-identity
     * form ("Name - Number - Set (CODE) - 1st Edition"); sealed products use the
     * natural retail wording instead (see {@see sealedSearchQuery()}).
     */
    public function searchQuery(CatalogItem $item): string
    {
        if ($item->item_type === ItemType::Sealed) {
            return $this->sealedSearchQuery($item);
        }

        $parts = [$item->name];

        if ($item->number) {
            $parts[] = $item->number;
        }

        $set = $item->set;
        if ($set) {
            $parts[] = $set->code ? "{$set->name} ({$set->code})" : $set->name;
        }

        // Pin the search to this exact printing (1st Edition / Shadowless / Reverse …).
        foreach (CardSearchTerms::qualifiers($item) as $term) {
            $parts[] = $term;
        }

        return implode(' - ', $parts);
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
