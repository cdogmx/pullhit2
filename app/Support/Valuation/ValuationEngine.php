<?php

namespace App\Support\Valuation;

use Carbon\CarbonInterface;

/**
 * The valuation engine (§7). Turns a set of sale observations for ONE priced
 * state into a confidence-scored distribution. Pure and framework-agnostic — no
 * Eloquent, no DB. Pipeline: lookback → robust outlier rejection → venue
 * normalization → velocity-aware EWMA → weighted distribution → confidence → trend.
 *
 * Thin/dispersed/stale markets yield LOW confidence, never an inflated point.
 */
class ValuationEngine
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>|null  $config  defaults to config('valuation')
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('valuation');
    }

    /**
     * @param  array<int, Observation>  $observations  all observations for one priced state
     */
    public function value(array $observations, ?CarbonInterface $asOf = null): ?ValuationResult
    {
        $asOfTs = $asOf?->getTimestamp() ?? time();
        $lookbackSec = (int) $this->config['lookback_days'] * 86400;

        $observations = array_values(array_filter($observations, function (Observation $o) use ($asOfTs, $lookbackSec) {
            $age = $asOfTs - $o->observedAt->getTimestamp();

            return $age >= -86400 && $age <= $lookbackSec;
        }));

        if ($observations === []) {
            return null;
        }

        // 1) Robust outlier rejection (Hampel/MAD) on raw prices.
        $prices = array_map(fn (Observation $o) => $o->priceCents, $observations);
        $flags = RobustFilter::flags($prices, (float) $this->config['mad_k']);

        $outlierKeys = [];
        $kept = [];
        foreach ($observations as $i => $o) {
            if ($flags[$i]) {
                $outlierKeys[] = $o->key;
            } else {
                $kept[] = $o;
            }
        }

        // 2) Venue normalization + ages.
        $priors = $this->config['venue_priors'];
        $norm = [];
        $ages = [];
        foreach ($kept as $o) {
            $norm[] = $o->priceCents * (float) ($priors[$o->venue] ?? 1.0);
            $ages[] = max(0.0, ($asOfTs - $o->observedAt->getTimestamp()) / 86400.0);
        }

        // 3) Velocity → half-life.
        $velocity = count($kept) / max(1.0, max($ages));
        $halfLife = $this->halfLife($velocity);

        // 4) EWMA recency weights → weighted distribution.
        $weights = array_map(fn ($age) => 0.5 ** ($age / $halfLife), $ages);
        $median = Stats::weightedQuantile($norm, $weights, 0.5);
        $p25 = Stats::weightedQuantile($norm, $weights, 0.25);
        $p75 = Stats::weightedQuantile($norm, $weights, 0.75);

        // 5) Source concentration (single-seller dominance) + confidence + 6) trend.
        $topSellerShare = $this->topSellerShare($kept);
        $confidence = $this->confidence(count($kept), min($ages), $median, $p25, $p75, $velocity, $topSellerShare);

        return new ValuationResult(
            median: (int) round($median),
            p25: (int) round($p25),
            p75: (int) round($p75),
            low: (int) round(min($norm)),
            high: (int) round(max($norm)),
            nSales: count($kept),
            confidence: round($confidence, 3),
            halfLifeDays: $halfLife,
            trend30d: $this->trend($kept, $priors, $asOfTs, 30),
            trend90d: $this->trend($kept, $priors, $asOfTs, 90),
            outlierKeys: $outlierKeys,
            topSellerShare: $topSellerShare !== null ? round($topSellerShare, 3) : null,
        );
    }

    /**
     * Share of the seller-tagged kept observations held by the single most
     * frequent seller, or null when too few carry a seller to judge. A high
     * share means one account is propping the "market" — a shill/wash signal.
     *
     * @param  array<int, Observation>  $kept
     */
    protected function topSellerShare(array $kept): ?float
    {
        $minN = (int) ($this->config['confidence']['concentration_min_n'] ?? 3);

        $counts = [];
        foreach ($kept as $o) {
            if ($o->seller !== null && $o->seller !== '') {
                $counts[$o->seller] = ($counts[$o->seller] ?? 0) + 1;
            }
        }

        $known = array_sum($counts);

        return $known >= $minN ? max($counts) / $known : null;
    }

    protected function halfLife(float $velocity): int
    {
        $hl = $this->config['half_life'];

        if ($velocity <= 0.0) {
            return (int) $hl['max_days'];
        }

        $days = (float) $hl['constant'] / $velocity;

        return (int) round(max((float) $hl['min_days'], min((float) $hl['max_days'], $days)));
    }

    protected function confidence(int $n, float $daysSinceNewest, float $median, float $p25, float $p75, float $velocity, ?float $topSellerShare): float
    {
        $c = $this->config['confidence'];

        $sample = min(1.0, $n / (float) $c['target_n']);
        $recency = exp(-$daysSinceNewest / (float) $c['recency_tau_days']);
        $dispersion = $median > 0 ? max(0.0, ($p75 - $p25) / $median) : 1.0;
        $dispersionFactor = 1.0 / (1.0 + $dispersion);
        $velocityPenalty = min(1.0, $velocity * (float) $c['full_velocity_days']);
        $concentration = $this->concentrationFactor($topSellerShare);

        return max(0.0, min(1.0, $sample * $recency * $dispersionFactor * $velocityPenalty * $concentration));
    }

    /**
     * Confidence multiplier for single-seller dominance: 1.0 below the threshold,
     * sliding down to `concentration_floor` when a single seller is behind every
     * comp. Null share (too few seller-tagged comps) means no penalty.
     */
    protected function concentrationFactor(?float $topSellerShare): float
    {
        $c = $this->config['confidence'];
        $threshold = (float) ($c['concentration_threshold'] ?? 0.5);
        $floor = (float) ($c['concentration_floor'] ?? 0.6);

        if ($topSellerShare === null || $topSellerShare <= $threshold) {
            return 1.0;
        }

        $normalized = min(1.0, ($topSellerShare - $threshold) / max(1e-9, 1.0 - $threshold));

        return max($floor, 1.0 - $normalized * (1.0 - $floor));
    }

    /**
     * Percent change of the recent window's median vs the prior equal-length window.
     *
     * @param  array<int, Observation>  $kept
     * @param  array<string, float>  $priors
     */
    protected function trend(array $kept, array $priors, int $asOfTs, int $days): ?float
    {
        $recent = [];
        $prior = [];

        foreach ($kept as $o) {
            $age = ($asOfTs - $o->observedAt->getTimestamp()) / 86400.0;
            $norm = $o->priceCents * (float) ($priors[$o->venue] ?? 1.0);

            if ($age <= $days) {
                $recent[] = $norm;
            } elseif ($age <= 2 * $days) {
                $prior[] = $norm;
            }
        }

        if ($recent === [] || $prior === []) {
            return null;
        }

        $priorMedian = Stats::median($prior);

        return $priorMedian > 0
            ? round((Stats::median($recent) / $priorMedian - 1) * 100, 2)
            : null;
    }
}
