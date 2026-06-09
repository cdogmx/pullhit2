<?php

namespace App\Support\Valuation;

/**
 * Small statistics helpers used by the valuation engine. Pure functions.
 */
final class Stats
{
    /**
     * @param  array<int, float|int>  $values
     */
    public static function median(array $values): float
    {
        $values = array_values($values);
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return 0.0;
        }

        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? (float) $values[$mid]
            : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    /**
     * Weighted quantile: the value at which cumulative weight first reaches
     * `q` of the total. Inputs are paired by index.
     *
     * @param  array<int, float|int>  $values
     * @param  array<int, float>  $weights
     */
    public static function weightedQuantile(array $values, array $weights, float $q): float
    {
        $pairs = [];
        foreach (array_values($values) as $i => $v) {
            $pairs[] = [(float) $v, (float) (array_values($weights)[$i] ?? 0.0)];
        }

        usort($pairs, fn ($a, $b) => $a[0] <=> $b[0]);

        $total = array_sum(array_column($pairs, 1));
        if ($total <= 0.0) {
            return $pairs === [] ? 0.0 : Stats::median(array_column($pairs, 0));
        }

        $cumulative = 0.0;
        foreach ($pairs as [$value, $weight]) {
            $cumulative += $weight;
            if ($cumulative >= $q * $total) {
                return $value;
            }
        }

        return (float) end($pairs)[0];
    }
}
