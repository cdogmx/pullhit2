<?php

namespace App\Support\Grading;

/**
 * The math behind "grade it or sell it raw?". Given a card's raw (Near Mint)
 * value, its graded values by grade, a probability distribution over the grade
 * it would come back, and the costs (grading fee + shipping, marketplace sale
 * fee), it computes the expected value of grading vs. selling raw, the advantage
 * between them, and the break-even PSA-10 probability.
 *
 * Pure and framework-agnostic (cents in, cents out). Sensei narrates the result;
 * this class owns the arithmetic so it can be reasoned about and tested.
 */
class GradeAdvisor
{
    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param  array<string, mixed>|null  $config  defaults to config('grading')
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('grading');
    }

    /**
     * @param  array<string, int>  $graded  grade ("10","9","8") => value in cents
     * @param  array<string, float>|null  $probs  grade => probability (remainder sells raw)
     */
    public function advise(int $raw, array $graded, ?array $probs = null): GradeAdvice
    {
        $probs ??= $this->normalizeProbs($this->config['default_probs']);
        $probs = $this->normalizeProbs($probs);

        $feeCents = (int) round(((float) $this->config['fee'] + (float) $this->config['shipping']) * 100);
        $saleFee = (float) $this->config['sale_fee_pct'];

        $net = fn (int $value): float => $value * (1 - $saleFee);

        // Probability the card grades so low you'd have done better selling raw.
        $pOther = max(0.0, 1.0 - array_sum($probs));

        $evGrade = -$feeCents + $net($raw) * $pOther;
        foreach ($probs as $grade => $p) {
            $evGrade += $p * $net($graded[$grade] ?? $raw);
        }

        $evRaw = $net($raw);
        $advantage = $evGrade - $evRaw;

        // Break-even P(10): the PSA-10 shot needed for grading to beat selling
        // raw, conservatively assuming every non-10 is worth only the raw value.
        // "You need at least a 1-in-N chance at a 10 to justify the fee."
        $ten = $net($graded['10'] ?? 0);
        $breakeven = $ten > $evRaw
            ? min(1.0, max(0.0, $feeCents / ($ten - $evRaw)))
            : null;

        $threshold = (float) $this->config['call_threshold'] * 100;
        $verdict = match (true) {
            $advantage > $threshold => 'grade',
            $advantage < -$threshold => 'sell',
            default => 'toss_up',
        };

        return new GradeAdvice(
            raw: $raw,
            evGrade: (int) round($evGrade),
            evRaw: (int) round($evRaw),
            advantage: (int) round($advantage),
            breakevenP10: $breakeven !== null ? round($breakeven, 3) : null,
            verdict: $verdict,
            feeCents: $feeCents,
            probs: $probs,
        );
    }

    /**
     * Clamp to non-negative and cap the total at 1 (scaling down if the caller
     * over-allocated), so the leftover mass is a valid "other" probability.
     *
     * @param  array<string, float>  $probs
     * @return array<string, float>
     */
    protected function normalizeProbs(array $probs): array
    {
        $clamped = [];
        foreach ($probs as $grade => $p) {
            $clamped[(string) $grade] = max(0.0, (float) $p);
        }

        $total = array_sum($clamped);
        if ($total > 1.0 && $total > 0) {
            foreach ($clamped as $grade => $p) {
                $clamped[$grade] = $p / $total;
            }
        }

        return $clamped;
    }
}
