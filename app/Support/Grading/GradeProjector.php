<?php

namespace App\Support\Grading;

/**
 * Turns a rolled-up 0–1000 condition score into a probability distribution over
 * the grades the dossier prices ("10", "9", "8"; the remainder is "other" — a
 * grade so low you'd have done better selling raw).
 *
 * Two deliberate properties, both consequences of how grading actually works:
 *
 *  1) It returns a DISTRIBUTION, never a grade. The estimate feeds straight into
 *     GradeAdvisor::advise(), which already reasons over probabilities — this
 *     just replaces its static config prior with a photo-derived one.
 *
 *  2) It is pessimistically biased when we could not see everything. An unseen
 *     defect can only ever drag a grade DOWN — there is no such thing as a
 *     scratch that improves a card — so anything we failed to observe shifts the
 *     mean down and widens the spread rather than being ignored.
 */
class GradeProjector
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param  array<string, mixed>|null  $config  defaults to config('grading') */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('grading');
    }

    /**
     * @param  int  $score  rolled-up 0–1000 condition score
     * @param  float  $sigma  uncertainty in that score, in points
     * @return array<string, float> grade => probability (need not sum to 1)
     */
    public function project(int $score, float $sigma): array
    {
        $bands = (array) ($this->config['score_bands'] ?? [
            '10' => [900, 1000],
            '9' => [800, 900],
            '8' => [700, 800],
        ]);

        $sigma = max(1.0, $sigma);
        $probs = [];

        foreach ($bands as $grade => [$low, $high]) {
            // The top band is open-ended upward: a 1000-point card is still a 10.
            $upper = $high >= 1000 ? INF : (float) $high;
            $p = $this->cdf((float) $upper, $score, $sigma) - $this->cdf((float) $low, $score, $sigma);

            if ($p > 0.001) {
                $probs[(string) $grade] = round($p, 4);
            }
        }

        return $probs;
    }

    /** P(X <= $x) for X ~ Normal($mean, $sigma). */
    private function cdf(float $x, float $mean, float $sigma): float
    {
        if (is_infinite($x)) {
            return $x > 0 ? 1.0 : 0.0;
        }

        return 0.5 * (1.0 + $this->erf(($x - $mean) / ($sigma * M_SQRT2)));
    }

    /**
     * Abramowitz & Stegun 7.1.26 — max error ~1.5e-7, far tighter than the
     * uncertainty in the score being fed through it.
     */
    private function erf(float $z): float
    {
        $sign = $z < 0 ? -1 : 1;
        $z = abs($z);

        $t = 1.0 / (1.0 + 0.3275911 * $z);

        // Written out term by term rather than folded, so it stays checkable.
        $poly = 0.254829592 * $t
            - 0.284496736 * $t ** 2
            + 1.421413741 * $t ** 3
            - 1.453152027 * $t ** 4
            + 1.061405429 * $t ** 5;

        return $sign * (1.0 - $poly * exp(-$z * $z));
    }
}
