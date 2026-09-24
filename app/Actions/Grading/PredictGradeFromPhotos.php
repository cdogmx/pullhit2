<?php

namespace App\Actions\Grading;

use App\Support\Grading\CardOutline;
use App\Support\Grading\Centering;
use App\Support\Grading\CenteringMeasurer;
use App\Support\Grading\ConditionRollup;
use App\Support\Grading\FrameWarper;
use App\Support\Grading\PhotoSequence;
use App\Support\Grading\Rect;
use App\Support\Grading\SurfaceAnalyzer;

/**
 * Runs the photo pipeline end to end: find the card in each frame, rectify the
 * sequence, read the surface, and turn what was observed into a distribution
 * over grades.
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
    public function __construct(
        protected CardOutline $outline,
        protected FrameWarper $warper,
        protected SurfaceAnalyzer $analyzer,
        protected ConditionRollup $rollup,
        protected CenteringMeasurer $centering,
    ) {}

    /**
     * @param  array<int, string>  $binaries  raw bytes, one per photo
     * @param  array<string, float>|null  $innerFrame  optional l/r/t/b of the
     *                                                 artwork border, 0–1 of the
     *                                                 card, for centering
     * @return array<string, mixed>
     */
    public function __invoke(
        array $binaries,
        int $maxInput = 1400,
        int $canvasWidth = 500,
        ?array $innerFrame = null,
    ): array {
        // The pipeline is memory-hungry by nature — several full-frame luma
        // arrays at once — and the default limit is not enough for a phone photo.
        @ini_set('memory_limit', '1024M');

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

        $analysis = $this->analyzer->analyze($rect['frames'], $rect['width'], $rect['height']);
        $maps = $this->analyzer->composites($rect['frames'], $rect['width'], $rect['height']);

        // Centering is arithmetic over two rectangles and nothing in the
        // pipeline finds the inner one yet, so it is only measured when a
        // person has marked it.
        $centering = $innerFrame !== null ? $this->measureCentering($innerFrame) : null;

        // Only what was actually observed is passed on. The rollup penalises
        // every attribute it was not shown, which is the honest answer here:
        // corners and edges have no detector at all yet.
        $observed = [];

        if ($analysis->isUsable()) {
            $observed['surface'] = $analysis->score;
        }

        if ($centering !== null) {
            $observed['centering'] = $centering->score;
        }

        $estimate = $this->rollup->roll($observed, $centering);

        return [
            'usable' => $analysis->isUsable(),
            'frames_used' => $analysis->framesUsed,
            'specular_range' => round($analysis->specularRange, 2),
            'canvas' => ['width' => $rect['width'], 'height' => $rect['height']],
            'corners' => $corners,
            'surface' => $analysis->toArray(),
            'centering' => $centering?->toArray(),
            'estimate' => $estimate->toArray(),
            'observed' => array_keys($observed),
            // The maps, for the eye. albedo should read as a clean flat card;
            // detail near-black except for scratches.
            'images' => [
                'albedo' => PhotoSequence::toDataUri($maps['min'], $rect['width'], $rect['height']),
                'detail' => PhotoSequence::toDataUri($maps['normalized'], $rect['width'], $rect['height'], autoScale: true),
                'frames' => array_map(
                    fn (array $f) => PhotoSequence::toDataUri($f, $rect['width'], $rect['height']),
                    $rect['frames'],
                ),
            ],
        ];
    }

    /**
     * @param  array<string, float>  $inner  l/r/t/b as a fraction of the card
     */
    private function measureCentering(array $inner): ?Centering
    {
        $left = (float) ($inner['left'] ?? 0);
        $top = (float) ($inner['top'] ?? 0);
        $right = (float) ($inner['right'] ?? 0);
        $bottom = (float) ($inner['bottom'] ?? 0);

        $width = 1.0 - $left - $right;
        $height = 1.0 - $top - $bottom;

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return $this->centering->measure(
            new Rect(0.0, 0.0, 1.0, 1.0),
            new Rect($left, $top, $width, $height),
        );
    }
}
