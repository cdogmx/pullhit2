<?php

namespace App\Support\Pricecharting;

/**
 * The two things we read from one PriceCharting product-page fetch: individual
 * completed sales (blended into valuations) and the long-term monthly price
 * series (the multi-year chart line).
 */
readonly class PricechartingData
{
    /**
     * @param  array<int, CompletedSale>  $comps
     * @param  array<int, array{t: string, price: int}>  $history  ungraded series (back-compat)
     * @param  array<string, array<int, array{t: string, price: int}>>  $histories  per-grade-tier series
     */
    public function __construct(
        public array $comps,
        public array $history,
        public array $histories = [],
    ) {}
}
