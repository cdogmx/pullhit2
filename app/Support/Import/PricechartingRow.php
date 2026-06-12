<?php

namespace App\Support\Import;

/**
 * One parsed row of a PriceCharting collection export, normalized toward our
 * model before catalog matching. Money is integer minor units (cents).
 * `costBasisCents` is the row total (PriceCharting stores per-row, not per-unit).
 */
readonly class PricechartingRow
{
    public function __construct(
        public string $externalId,      // PriceCharting product id
        public string $name,            // "Beautifly"
        public ?string $number,         // "219"
        public ?string $variant,        // "reverse" (from a "[Reverse]" tag), else null
        public string $setName,         // "White Flare" (language + product-line stripped)
        public string $language,        // "en" | "ja" | "zh" | "ko" | …
        public string $productLine,     // "pokemon" | "one piece" | …
        public ?string $condition,      // "NM" for raw (PriceCharting lacks granularity); null when graded
        public ?string $gradingCompany, // "cgc" | "psa" | … | null
        public ?float $grade,           // 10, 9.5, 8 … | null
        public int $quantity,
        public int $costBasisCents,     // row total; divide by quantity for unit cost
        public ?string $folder,         // PriceCharting "folder" grouping
        public ?string $notes,
        public ?string $acquiredAt,     // date-purchased (Y-m-d) or null
    ) {}

    public function isGraded(): bool
    {
        return $this->grade !== null;
    }
}
