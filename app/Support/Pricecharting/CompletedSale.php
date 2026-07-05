<?php

namespace App\Support\Pricecharting;

use App\Enums\Venue;
use Carbon\CarbonImmutable;

/**
 * One completed sale scraped from a PriceCharting product page's sold-listings
 * table. PriceCharting aggregates eBay + TCGplayer sold data, tagging each row's
 * source — which we preserve as the venue when ingesting.
 */
readonly class CompletedSale
{
    public function __construct(
        public Venue $source,
        public string $listingId,
        public string $title,
        public int $priceCents,
        public ?CarbonImmutable $soldAt,
        public ?string $url = null,
    ) {}
}
