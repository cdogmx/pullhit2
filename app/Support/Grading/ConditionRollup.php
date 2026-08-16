<?php

namespace App\Support\Grading;

/**
 * Rolls observed per-attribute scores up into one condition estimate, applying
 * the weakest-link rule and the pessimism that unobserved attributes demand.
 * See {@see ConditionEstimate} for where the weakest-link rule comes from.
 *
 * Pure: scores in, estimate out. The vision work that produces the scores lives
 * in the action; the judgement about what those scores MEAN lives here, so it
 * can be tested without a model call.
 */
class ConditionRollup
{
    /** Every attribute a full grade accounts for. */
    public const ATTRIBUTES = ['centering', 'corners', 'edges', 'surface'];

    /** @var array<string, mixed> */
    protected array $config;

    public function __construct(
        protected GradeProjector $projector = new GradeProjector,
        ?array $config = null,
    ) {
        $this->config = $config ?? (array) config('grading');
    }

    /**
     * @param  array<string, int>  $observed  attribute => 0–1000 score
     */
    public function roll(array $observed, ?Centering $centering = null): ConditionEstimate
    {
        $observed = array_filter(
            $observed,
            fn ($v, $k) => in_array($k, self::ATTRIBUTES, true) && is_numeric($v),
            ARRAY_FILTER_USE_BOTH,
        );
        $observed = array_map(fn ($v) => (int) max(0, min(1000, $v)), $observed);

        $unseen = array_values(array_diff(self::ATTRIBUTES, array_keys($observed)));

        // Weakest link. With nothing observed there is no evidence at all, and we
        // say so rather than inventing a midpoint.
        $score = $observed === [] ? 0 : (int) min($observed);
        $sigma = (float) ($this->config['sigma_base'] ?? 25);

        // Each attribute we could not see can only have dragged the real score
        // down, never up: shift the mean down and widen the spread.
        $penalty = (array) ($this->config['unseen_penalty'] ?? []);
        foreach ($unseen as $attribute) {
            $score -= (int) ($penalty[$attribute] ?? 30);
            $sigma += (float) ($this->config['sigma_per_unseen'] ?? 20);
        }

        $score = (int) max(0, min(1000, $score));

        return new ConditionEstimate(
            attributes: $observed,
            unseen: $unseen,
            score: $score,
            sigma: $sigma,
            probs: $observed === [] ? [] : $this->projector->project($score, $sigma),
            centering: $centering,
        );
    }
}
