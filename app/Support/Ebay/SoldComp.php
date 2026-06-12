<?php

namespace App\Support\Ebay;

use Carbon\CarbonImmutable;

/**
 * A normalized, accepted eBay sold comp ready to become a sale_observation. Its
 * priced state is resolved from the title: graded (company + grade) or raw
 * (condition). `sourceListingId` is the eBay item id, used to dedupe re-pulls.
 */
readonly class SoldComp
{
    public function __construct(
        public int $priceCents,
        public ?CarbonImmutable $soldAt,
        public ?string $condition,
        public ?int $gradingCompanyId,
        public ?float $grade,
        public ?string $gradeLabel,
        public string $sourceListingId,
        public string $title,
        public ?string $url = null,
        public ?string $seller = null,
    ) {}
}
