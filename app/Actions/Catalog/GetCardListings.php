<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Support\Affiliate\EbayAffiliate;
use App\Support\Affiliate\TcgplayerAffiliate;
use App\Support\Ebay\EbayBrowseClient;
use Illuminate\Support\Facades\Cache;

/**
 * Active "buy it now" listings + affiliate links for a card. Live eBay listings
 * come from the Browse API (cached, free); the eBay/TCGplayer shop links are
 * always present (affiliate-tagged when configured).
 */
class GetCardListings
{
    public function __construct(
        protected EbayBrowseClient $browse,
        protected EbayAffiliate $ebay,
        protected TcgplayerAffiliate $tcgplayer,
    ) {}

    /**
     * @return array{listings: array<int, mixed>, ebay_url: string, tcgplayer_url: string, configured: bool}
     */
    public function __invoke(CatalogItem $item): array
    {
        $query = trim(implode(' ', array_filter([
            $item->name,
            $item->number,
            $item->set?->name,
        ])));

        $listings = Cache::remember(
            "ebay:listings:{$item->id}",
            now()->addHours(6),
            fn () => $this->browse->search($query),
        );

        return [
            'listings' => $listings,
            'ebay_url' => $this->ebay->searchUrl($query),
            'tcgplayer_url' => $this->tcgplayer->searchUrl($item->name.' '.($item->set?->name ?? '')),
            'configured' => $this->browse->configured(),
        ];
    }
}
