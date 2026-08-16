<?php

namespace App\Support\Grading;

/**
 * A measured centering result, in the same shape TAG publishes it: the left/right
 * and top/bottom margin split as percentages that sum to 100, plus the 0–1000
 * score those ratios earn.
 *
 * TAG's report for cert Y1267951 reads "C: 53.31 / C: 46.69" horizontally and
 * "C: 48.13 / C: 51.87" vertically, scoring 970 on the front — the anchor this
 * class is calibrated against.
 */
readonly class Centering
{
    public function __construct(
        public float $left,
        public float $right,
        public float $top,
        public float $bottom,
        public int $score,
    ) {}

    /**
     * How far off dead-centre the worse axis is, in percentage points. A perfect
     * 50/50 card is 0; the Griffey's 53.31/46.69 is 3.31.
     */
    public function worstDeviation(): float
    {
        return round(max(
            abs($this->left - 50.0),
            abs($this->top - 50.0),
        ), 2);
    }

    /** Which axis is the binding one — the thing to tell the user about. */
    public function worstAxis(): string
    {
        return abs($this->left - 50.0) >= abs($this->top - 50.0)
            ? 'left-right'
            : 'top-bottom';
    }

    /** "53.3 / 46.7 left-right" — the human-facing phrasing. */
    public function describe(): string
    {
        [$a, $b] = $this->worstAxis() === 'left-right'
            ? [$this->left, $this->right]
            : [$this->top, $this->bottom];

        return sprintf('%.1f / %.1f %s', $a, $b, $this->worstAxis());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'left' => round($this->left, 2),
            'right' => round($this->right, 2),
            'top' => round($this->top, 2),
            'bottom' => round($this->bottom, 2),
            'score' => $this->score,
            'worst_axis' => $this->worstAxis(),
            'worst_deviation' => $this->worstDeviation(),
            'description' => $this->describe(),
        ];
    }
}
