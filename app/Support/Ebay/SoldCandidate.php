<?php

namespace App\Support\Ebay;

use Carbon\CarbonImmutable;

/**
 * A raw sold listing parsed from eBay search HTML, before filtering/classifying.
 */
readonly class SoldCandidate
{
    public function __construct(
        public string $title,
        public int $priceCents,
        public ?CarbonImmutable $soldAt,
        public ?string $itemId,
        public ?string $url = null,
        public ?string $seller = null,
        public ?string $imageUrl = null,
    ) {}
}
