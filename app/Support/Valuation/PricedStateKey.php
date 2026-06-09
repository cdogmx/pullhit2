<?php

namespace App\Support\Valuation;

use App\Models\GradingCompany;

/**
 * Canonical, compact identifier for a priced state — e.g. "NM", "SEALED",
 * "psa-10", "bgs-9.5". Used as the unique key on market_values so nullable
 * condition/grade columns still dedupe reliably.
 */
final class PricedStateKey
{
    public static function for(?string $condition, ?int $gradingCompanyId, ?float $grade): string
    {
        if ($gradingCompanyId !== null) {
            $slug = GradingCompany::whereKey($gradingCompanyId)->value('slug') ?? "company{$gradingCompanyId}";
            $g = $grade !== null ? rtrim(rtrim(sprintf('%.1f', $grade), '0'), '.') : '?';

            return "{$slug}-{$g}";
        }

        return $condition ?? 'raw';
    }
}
