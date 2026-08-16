<?php

namespace App\Support\Grading;

use InvalidArgumentException;

/**
 * Turns two rectangles — a card's outer edge and its inner frame — into the
 * centering ratios and a 0–1000 score.
 *
 * This is the one grading attribute we can measure rather than guess: it is pure
 * geometry, it does not depend on lighting, and the user can check our answer
 * against their own card with a ruler. Locating the two rectangles is the vision
 * model's job (card designs vary far too much for a design-agnostic edge
 * detector); everything from the rectangles onward is arithmetic and lives here
 * so it can be tested and argued with.
 *
 * Calibration: TAG cert Y1267951 measures 53.31/46.69 left-right, 48.13/51.87
 * top-bottom, and scores 970 on the front. A linear penalty in the worse axis's
 * deviation fits that anchor at ~9.06 points per percentage point off centre.
 * Reassuringly, that same line puts 60/40 at 909 and 65/35 at 864 — close to
 * where PSA's published tolerances put a 9 and an 8/8.5. It is still a fit to a
 * SINGLE anchor: treat the constant as provisional and recalibrate it as more
 * certs arrive, which is why it lives in config rather than here.
 */
class CenteringMeasurer
{
    public function __construct(private ?float $penaltyPerPoint = null) {}

    /**
     * @throws InvalidArgumentException when the frame isn't inside the card — a
     *                                  misread we must refuse rather than report a bogus ratio for.
     */
    public function measure(Rect $card, Rect $frame): Centering
    {
        if ($card->width <= 0 || $card->height <= 0) {
            throw new InvalidArgumentException('Card rectangle has no area.');
        }

        if (! $card->contains($frame)) {
            throw new InvalidArgumentException('Frame is not inside the card outline.');
        }

        $leftMargin = $frame->x - $card->x;
        $rightMargin = $card->right() - $frame->right();
        $topMargin = $frame->y - $card->y;
        $bottomMargin = $card->bottom() - $frame->bottom();

        // A frame flush to an edge on both sides carries no centering signal.
        if ($leftMargin + $rightMargin <= 0 || $topMargin + $bottomMargin <= 0) {
            throw new InvalidArgumentException('Frame has no measurable margin on one axis.');
        }

        $left = $leftMargin / ($leftMargin + $rightMargin) * 100;
        $top = $topMargin / ($topMargin + $bottomMargin) * 100;

        $deviation = max(abs($left - 50.0), abs($top - 50.0));

        return new Centering(
            left: $left,
            right: 100 - $left,
            top: $top,
            bottom: 100 - $top,
            score: $this->score($deviation),
        );
    }

    /** 0–1000 from the worse axis's deviation off centre, floored at 0. */
    public function score(float $deviation): int
    {
        $penalty = $this->penaltyPerPoint
            ?? (float) config('grading.centering_penalty_per_point', 9.06);

        return (int) max(0, min(1000, round(1000 - $penalty * abs($deviation))));
    }
}
