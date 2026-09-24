<?php

namespace App\Actions\Grading;

use App\Support\Grading\CardOutline;
use App\Support\Grading\Centering;
use App\Support\Grading\CenteringMeasurer;
use App\Support\Grading\ConditionRollup;
use App\Support\Grading\FrameWarper;
use App\Support\Grading\PhotoSequence;
use App\Support\Grading\SurfaceAnalyzer;

/**
 * Runs the photo pipeline over a card's sides and turns what was observed into
 * a distribution over grades.
 *
 * Front and back are read separately because that is how a card is graded. TAG's
 * report for cert Y1267951 scores the front 876 and the back 915 and calls the
 * card 879 — the front's number, not the average. Both sides get their own tilt
 * sequence, their own surface read and their own centering.
 *
 * It returns the intermediate maps as well as the numbers, because the pipeline
 * cannot be judged from a score alone — a detail map showing artwork means the
 * frames did not align, and that looks identical in the numbers to a card
 * covered in scratches.
 *
 * Nothing here decides a grade. GradeProjector deliberately answers with a
 * distribution, and an unusable sequence answers with nothing at all rather
 * than a confident number derived from noise.
 */
class PredictGradeFromPhotos
{
    /** The sides a card has, in the order a person photographs them. */
    public const SIDES = ['front', 'back'];

    public function __construct(
        protected CardOutline $outline,
        protected FrameWarper $warper,
        protected SurfaceAnalyzer $analyzer,
        protected ConditionRollup $rollup,
        protected CenteringMeasurer $centering,
    ) {}

    /**
     * @param  array<string, array<int, string>>  $sides  side => raw photo bytes
     * @param  array<string, array<string, float>>  $centering  side => the
     *                                                          centering split as a grading report
     *                                                          writes it, e.g. 46 left / 54 right
     * @return array<string, mixed>
     */
    public function __invoke(
        array $sides,
        int $maxInput = 1400,
        int $canvasWidth = 500,
        array $centering = [],
    ): array {
        // The pipeline is memory-hungry by nature — several full-frame luma
        // arrays at once — and the default limit is not enough for a phone photo.
        @ini_set('memory_limit', '1024M');

        $results = [];

        foreach (self::SIDES as $side) {
            if (($sides[$side] ?? []) === []) {
                continue;
            }

            $results[$side] = $this->readSide(
                $sides[$side],
                $maxInput,
                $canvasWidth,
                $centering[$side] ?? null,
            );
        }

        // Weakest link, across sides as well as attributes. A scratch on the
        // back holds the card back exactly as a scratch on the front does, and
        // the rollup's whole premise is that one bad attribute sets the grade.
        $observed = [];

        foreach ($results as $side) {
            foreach ($side['observed'] as $attribute => $score) {
                $observed[$attribute] = isset($observed[$attribute])
                    ? min($observed[$attribute], $score)
                    : $score;
            }
        }

        // The worse side's centering, for the same reason.
        $worst = null;

        foreach ($results as $side) {
            if ($side['centering'] !== null
                && ($worst === null || $side['centering']->score < $worst->score)) {
                $worst = $side['centering'];
            }
        }

        $estimate = $this->rollup->roll($observed, $worst);

        return [
            'sides' => array_map(
                fn (array $s) => [
                    'surface_assessable' => $s['surface_assessable'],
                    'usable' => $s['usable'],
                    'frames_used' => $s['frames_used'],
                    'specular_range' => $s['specular_range'],
                    'canvas' => $s['canvas'],
                    'surface' => $s['surface'],
                    'centering' => $s['centering']?->toArray(),
                    'images' => $s['images'],
                ],
                $results,
            ),
            'estimate' => $estimate->toArray(),
            'observed' => array_keys($observed),
            // Which side set each attribute, so a bad number can be traced to
            // the photos that produced it rather than to "the card".
            'limited_by_side' => $this->limitingSide($results, $observed),
        ];
    }

    /**
     * @param  array<int, string>  $binaries
     * @param  array<string, float>|null  $split
     * @return array<string, mixed>
     */
    private function readSide(
        array $binaries,
        int $maxInput,
        int $canvasWidth,
        ?array $split,
    ): array {
        $photos = PhotoSequence::fromBinaries($binaries, $maxInput);

        $corners = array_map(
            fn (array $luma) => $this->outline->detect($luma, $photos->width, $photos->height),
            $photos->frames,
        );

        $rect = $this->warper->rectifySequence(
            $photos->frames,
            $photos->width,
            $photos->height,
            $corners,
            $canvasWidth,
        );

        // One photo is allowed, and it still buys the rectified card and a
        // place to put the centering. What it cannot buy is a surface read:
        // the method is differencing frames against each other, and there is
        // nothing to difference. Saying so is the honest answer — a single
        // photo of a scratched card looks exactly like a single photo of a
        // clean one.
        $assessable = count($photos->frames) >= 2;

        $analysis = $assessable
            ? $this->analyzer->analyze($rect['frames'], $rect['width'], $rect['height'])
            : null;

        $maps = $assessable
            ? $this->analyzer->composites($rect['frames'], $rect['width'], $rect['height'])
            : null;

        // Centering is arithmetic over two rectangles and nothing in the
        // pipeline finds the inner one yet, so it is only measured when a
        // person has marked it — and it is marked per side, because the front
        // and back of the same card are cut differently.
        $centering = $split !== null ? $this->centeringFromSplit($split) : null;

        $observed = [];

        if ($analysis !== null && $analysis->isUsable()) {
            $observed['surface'] = $analysis->score;
        }

        if ($centering !== null) {
            $observed['centering'] = $centering->score;
        }

        return [
            // "Assessable" and "usable" are different failures and must not be
            // conflated. One photo CANNOT carry surface — that is physics, not
            // a bad shot. Several photos that did not move the glare COULD have
            // and did not — that is a re-shoot.
            'surface_assessable' => $assessable,
            'usable' => $analysis?->isUsable() ?? false,
            'frames_used' => count($rect['frames']),
            'specular_range' => $analysis !== null ? round($analysis->specularRange, 2) : null,
            'canvas' => ['width' => $rect['width'], 'height' => $rect['height']],
            'surface' => $analysis?->toArray(),
            'centering' => $centering,
            'observed' => $observed,
            // The maps, for the eye. albedo should read as a clean flat card;
            // detail near-black except for scratches. With one frame there is
            // no albedo to separate and no detail to stretch, so only the
            // rectified card is shown.
            'images' => [
                'albedo' => $maps !== null
                    ? PhotoSequence::toDataUri($maps['min'], $rect['width'], $rect['height'])
                    : null,
                'detail' => $maps !== null
                    ? PhotoSequence::toDataUri($maps['normalized'], $rect['width'], $rect['height'], autoScale: true)
                    : null,
                'frames' => array_map(
                    fn (array $f) => PhotoSequence::toDataUri($f, $rect['width'], $rect['height']),
                    $rect['frames'],
                ),
            ],
        ];
    }

    /**
     * Which side supplied the worst reading for each attribute.
     *
     * @param  array<string, array<string, mixed>>  $results
     * @param  array<string, int>  $observed
     * @return array<string, string>
     */
    private function limitingSide(array $results, array $observed): array
    {
        $out = [];

        foreach ($observed as $attribute => $score) {
            foreach ($results as $name => $side) {
                if (($side['observed'][$attribute] ?? null) === $score) {
                    $out[$attribute] = $name;

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Centering from the split a grading report prints.
     *
     * TAG writes the Milotic's front as "46L/54R 47T/53B" and the Griffey's as
     * "53.31 / 46.69" — a RATIO between the two margins, not their size against
     * the card. Reading those four numbers as margins computes a card of zero
     * width, which is how this arrived: the first version of the form asked for
     * margins and could not accept the very report it exists to be checked
     * against.
     *
     * Nothing in the pipeline produces these yet. A person reads them off a
     * report, or measures them, which is exactly what the bench is for.
     *
     * @param  array<string, float>  $split  left/right/top/bottom, summing to
     *                                       100 per axis
     */
    private function centeringFromSplit(array $split): ?Centering
    {
        $left = (float) ($split['left'] ?? 0);
        $right = (float) ($split['right'] ?? 0);
        $top = (float) ($split['top'] ?? 0);
        $bottom = (float) ($split['bottom'] ?? 0);

        if ($left + $right <= 0 || $top + $bottom <= 0) {
            return null;
        }

        // Normalised rather than trusted to sum to 100: a report rounds, and a
        // person typing 46/54 should get the same answer as one typing 0.46/0.54.
        $leftPct = $left / ($left + $right) * 100;
        $topPct = $top / ($top + $bottom) * 100;

        return new Centering(
            left: round($leftPct, 2),
            right: round(100 - $leftPct, 2),
            top: round($topPct, 2),
            bottom: round(100 - $topPct, 2),
            score: $this->centering->score(
                max(abs($leftPct - 50.0), abs($topPct - 50.0)),
            ),
        );
    }
}
