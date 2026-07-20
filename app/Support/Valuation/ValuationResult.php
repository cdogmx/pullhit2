<?php

namespace App\Support\Valuation;

/**
 * The output distribution for one priced state — never a single point (§7).
 * Money fields are integer minor units (cents).
 */
readonly class ValuationResult
{
    /**
     * @param  array<int, int|string>  $outlierKeys  keys of observations flagged as outliers
     */
    public function __construct(
        public int $median,
        public int $p25,
        public int $p75,
        public int $low,
        public int $high,
        public int $nSales,
        public float $confidence,
        public int $halfLifeDays,
        public ?float $trend1d,
        public ?float $trend7d,
        public ?float $trend30d,
        public ?float $trend90d,
        public array $outlierKeys = [],
        /** Share of seller-tagged comps held by the single most-frequent seller (null = too few to judge). */
        public ?float $topSellerShare = null,
    ) {}
}
