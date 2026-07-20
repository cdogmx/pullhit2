<?php

namespace App\Support\Valuation;

/**
 * Turns a bag of current asking prices for one priced state into the "lowest
 * realistic ask" — what you could actually buy the card for right now. Pure and
 * framework-agnostic (cents in, cents out).
 *
 * Live listings are noisy: a mispriced $5 "lot", a wrong-card match, and a
 * $9,999 moonshot all sit unsold next to the real market. So we drop asks far
 * from the median ask, then take a low percentile of what survives — near the
 * cheapest genuine listing, but not the single lowest (often still a lowball).
 */
class ForSaleEngine
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>|null  $config  defaults to config('valuation.for_sale')
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('valuation.for_sale');
    }

    /**
     * @param  array<int, int>  $asks  asking prices in cents
     * @return array{for_sale: int, n: int}|null null when too few plausible asks
     */
    public function value(array $asks): ?array
    {
        $asks = array_values(array_filter($asks, fn ($a) => $a > 0));

        if (count($asks) < (int) $this->config['min_asks']) {
            return null;
        }

        $median = Stats::median($asks);
        if ($median <= 0) {
            return null;
        }

        $floor = $median * (float) $this->config['floor_frac'];
        $ceil = $median * (float) $this->config['ceil_frac'];

        $kept = array_values(array_filter($asks, fn ($a) => $a >= $floor && $a <= $ceil));

        if (count($kept) < (int) $this->config['min_asks']) {
            return null;
        }

        sort($kept);
        $forSale = $this->percentile($kept, (float) $this->config['low_percentile']);

        return ['for_sale' => (int) round($forSale), 'n' => count($kept)];
    }

    /**
     * Linear-interpolated percentile of a pre-sorted list.
     *
     * @param  array<int, int>  $sorted  ascending
     */
    protected function percentile(array $sorted, float $q): float
    {
        $n = count($sorted);
        if ($n === 1) {
            return (float) $sorted[0];
        }

        $pos = $q * ($n - 1);
        $lo = (int) floor($pos);
        $hi = (int) ceil($pos);
        $frac = $pos - $lo;

        return $sorted[$lo] + ($sorted[$hi] - $sorted[$lo]) * $frac;
    }
}
