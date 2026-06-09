<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;

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

    /** "Name - Number - Set Name (CODE)" — the card's display identity. */
    public function searchQuery(CatalogItem $item): string
    {
        $parts = [$item->name];

        if ($item->number) {
            $parts[] = $item->number;
        }

        $set = $item->set;
        if ($set) {
            $parts[] = $set->code ? "{$set->name} ({$set->code})" : $set->name;
        }

        return implode(' - ', $parts);
    }
}
