<?php

namespace App\Support\Valuation;

use Carbon\CarbonInterface;

/**
 * A single market signal fed to the valuation engine — framework-agnostic, no
 * Eloquent. `key` is an opaque caller reference (e.g. the DB id) so the engine
 * can report which observations it flagged as outliers.
 */
readonly class Observation
{
    public function __construct(
        public int $priceCents,
        public string $venue,
        public CarbonInterface $observedAt,
        public int|string|null $key = null,
    ) {}
}
