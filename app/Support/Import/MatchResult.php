<?php

namespace App\Support\Import;

use App\Models\CatalogItem;

/**
 * The outcome of matching one PriceCharting row to our catalog.
 * `status` is matched | ambiguous | unmatched; `reason` is a short code for stats.
 */
readonly class MatchResult
{
    public function __construct(
        public PricechartingRow $row,
        public ?CatalogItem $catalogItem,
        public string $status,
        public string $reason,
    ) {}
}
