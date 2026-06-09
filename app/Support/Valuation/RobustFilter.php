<?php

namespace App\Support\Valuation;

/**
 * Robust outlier rejection via the Hampel identifier: a point is an outlier when
 * |x - median| > k · 1.4826 · MAD (1.4826 makes MAD a consistent estimator of σ
 * for normal data). Far more stable than mean ± stddev for thin/skewed markets.
 */
final class RobustFilter
{
    /**
     * @param  array<int, float|int>  $values
     * @return array<int, bool> outlier flag per input index (preserves keys)
     */
    public static function flags(array $values, float $k = 3.0): array
    {
        if (count($values) < 3) {
            return array_map(fn () => false, $values);
        }

        $median = Stats::median($values);
        $mad = Stats::median(array_map(fn ($v) => abs((float) $v - $median), $values));

        // No spread (or near-duplicate data): nothing to reject.
        if ($mad <= 0.0) {
            return array_map(fn () => false, $values);
        }

        $threshold = $k * 1.4826 * $mad;

        return array_map(fn ($v) => abs((float) $v - $median) > $threshold, $values);
    }
}
