<?php

namespace App\Support\Ebay;

use RuntimeException;

/**
 * Thrown when an Oxylabs fetch is refused because its daily budget is already
 * spent. Distinct from a fetch *failure* — nothing was requested and nothing was
 * billed, so callers should back off until tomorrow rather than retry.
 */
class OxylabsBudgetException extends RuntimeException
{
    public function __construct(public readonly string $budget, public readonly int $cap)
    {
        parent::__construct("Oxylabs daily cap reached for '{$budget}' ({$cap} requests).");
    }
}
