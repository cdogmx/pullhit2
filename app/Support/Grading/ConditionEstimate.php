<?php

namespace App\Support\Grading;

/**
 * A photo-derived best guess at a card's condition: the per-attribute 0–1000
 * scores we could observe, the rolled-up score, what we could NOT see, and the
 * resulting probability distribution over grades.
 *
 * The roll-up is weakest-link, which is not a guess — it is read off TAG's own
 * report for cert Y1267951. Their front sub-scores are centering 970, corners
 * 964, surface 867, edges 1000 and the reported front total is 876: the minimum
 * (867), not the mean (950). The back reads 984 / 923 / 986 / 912 and totals
 * 915 against a minimum of 912. Overall (876 front, 915 back) is 879 — hugging
 * the worse side again. One bad attribute sets the grade; the rest is detail.
 *
 * That is why the estimate must be pessimistic. A card can be perfect everywhere
 * we looked and still grade poorly on the one thing a flat photo cannot show,
 * which is exactly what happened to the Griffey: near-perfect centering and
 * literally perfect front edges, dragged to an 8.5 by surface scratches.
 */
readonly class ConditionEstimate
{
    /**
     * @param  array<string, int>  $attributes  attribute => 0–1000 score, observed only
     * @param  array<int, string>  $unseen  attributes we could not judge from the photos
     * @param  array<string, float>  $probs  grade => probability, for GradeAdvisor
     */
    public function __construct(
        public array $attributes,
        public array $unseen,
        public int $score,
        public float $sigma,
        public array $probs,
        public ?Centering $centering = null,
    ) {}

    /** The attribute that set the score — the one thing worth telling the user. */
    public function limitingAttribute(): ?string
    {
        if ($this->attributes === []) {
            return null;
        }

        return (string) array_search(min($this->attributes), $this->attributes, true);
    }

    /** How much of the grade we actually had evidence for. */
    public function isConfident(): bool
    {
        return $this->unseen === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'attributes' => $this->attributes,
            'unseen' => $this->unseen,
            'score' => $this->score,
            'sigma' => round($this->sigma, 1),
            'probs' => $this->probs,
            'limiting_attribute' => $this->limitingAttribute(),
            'confident' => $this->isConfident(),
            'centering' => $this->centering?->toArray(),
        ];
    }
}
