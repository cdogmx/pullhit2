<?php

namespace App\Actions\Grading;

use App\Support\Grading\CardOutline;
use App\Support\Grading\Centering;
use App\Support\Grading\CenteringMeasurer;
use App\Support\Grading\CenteringStandards;
use App\Support\Grading\ConditionRollup;
use App\Support\Grading\FrameWarper;
use App\Support\Grading\Homography;
use App\Support\Grading\PhotoSequence;
use App\Support\Grading\Quad;
use App\Support\Grading\Rect;
use App\Support\Grading\SurfaceAnalyzer;
use InvalidArgumentException;

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
        array $guides = [],
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
                $guides[$side] ?? null,
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
                    'detail_coverage' => $s['detail_coverage'],
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
            // What each company's published centering tolerance allows, front
            // and back judged against their OWN limits. Separate from the
            // score above, which is weakest-link across both sides because
            // that is how TAG's own overall behaves.
            'centering_standards' => CenteringStandards::assess(
                $results['front']['centering'] ?? null,
                $results['back']['centering'] ?? null,
            ),
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
        ?array $guides,
    ): array {
        $photos = PhotoSequence::fromBinaries($binaries, $maxInput);

        // Every frame is found on its own terms, and this is not a detail.
        //
        // The surface read differences the frames against each other, so they
        // have to be rectified to the same card — which means each needs the
        // corners of the card IN IT. A hand holding a phone moves between
        // shots; warping every frame with one frame's corners leaves them
        // offset, and differencing offset frames produces an edge along every
        // printed line on the card. That reads as dozens of scratches and
        // scores like a damaged card. It is what the first real capture did:
        // sixty-one defects, and a detail map showing the artwork twice.
        //
        // The dragged outline is a fallback, not an override. It is one frame's
        // answer — useful when detection finds nothing at all, and wrong for
        // every other frame.
        $outline = $this->pixelQuad($guides['outline'] ?? null, $photos->width, $photos->height);

        $corners = array_map(
            function (array $luma) use ($photos, $outline) {
                $found = $this->outline->detect($luma, $photos->width, $photos->height);

                return count($found) === 4 ? $found : $outline;
            },
            $photos->frames,
        );

        // A frame whose card could not be found, and which has no fallback,
        // cannot be aligned to the others. Dropping it is the lesser harm: a
        // misaligned frame invents damage, where a missing one only costs
        // sensitivity.
        $frames = [];
        $kept = [];

        foreach ($corners as $i => $quad) {
            if ($quad !== null) {
                $frames[] = $photos->frames[$i];
                $kept[] = $quad;
            }
        }

        $corners = $kept;

        $rect = $this->warper->rectifySequence(
            $frames,
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
        $assessable = count($frames) >= 2;

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
        // Guides first: two rectangles is what CenteringMeasurer was built to
        // take, and dragging them is a measurement. A typed split is a figure
        // read off somebody else's report — useful for checking ourselves
        // against one, but not a measurement of these photos.
        //
        // frame_uv is the inner border as placed on the STRAIGHTENED card,
        // which is already card space — the outline quad defines that space, so
        // those coordinates need no mapping and get none. Mapping them anyway,
        // through a homography they are already the output of, would apply the
        // correction twice.
        $centering = $this->centeringFromCardSpace($guides['frame_uv'] ?? null)
            ?? $this->centeringFromGuides(
                $outline ?? ($corners[0] ?? null),
                $this->pixelQuad($guides['frame'] ?? null, $photos->width, $photos->height),
            )
            ?? ($split !== null ? $this->centeringFromSplit($split) : null);

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
            // What fraction of the detail map carries signal. Scratches are
            // sparse, so a clean read lights a few percent of the card;
            // misaligned frames light every printed edge on it. It is reported
            // rather than acted on — the threshold is one real capture old.
            'detail_coverage' => $maps !== null ? self::coverage($maps['normalized']) : null,
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
     * The share of the detail map carrying real signal.
     *
     * Measured against the map's own range rather than an absolute level,
     * because the range is arbitrary — it is a difference of differences. A
     * clean card lights a few percent: the scratches. Frames that did not align
     * light every edge the printing has, which is most of the card.
     *
     * @param  array<int, float>  $map
     */
    private static function coverage(array $map): float
    {
        if ($map === []) {
            return 0.0;
        }

        $lo = min($map);
        $hi = max($map);

        if ($hi - $lo < 1e-6) {
            return 0.0;
        }

        $cut = $lo + ($hi - $lo) * 0.25;
        $lit = 0;

        foreach ($map as $v) {
            if ($v > $cut) {
                $lit++;
            }
        }

        return round($lit / count($map), 4);
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
     * A guide quad, given as fractions of the image, in pixels.
     *
     * @param  array<int, array{x: float, y: float}>|null  $quad
     * @return array<int, array{0: float, 1: float}>|null
     */
    private function pixelQuad(?array $quad, int $width, int $height): ?array
    {
        if ($quad === null || count($quad) !== 4) {
            return null;
        }

        // Same ordering rule as the warp: rectifySequence fits a homography
        // to these, and a quad starting from the wrong corner rectifies every
        // frame sideways.
        return Quad::ordered(array_map(
            fn (array $p) => [(float) $p['x'] * $width, (float) $p['y'] * $height],
            array_values($quad),
        ));
    }

    /**
     * Centering from an inner border already expressed in card space.
     *
     * The card was straightened before the border was placed on it, so these
     * fractions are of the flattened card itself. That is the output of the
     * very homography centeringFromGuides applies — running it again would
     * correct for a perspective that has already been removed.
     *
     * @param  array<int, array{x: float, y: float}>|null  $frame
     */
    private function centeringFromCardSpace(?array $frame): ?Centering
    {
        if ($frame === null || count($frame) !== 4) {
            return null;
        }

        $xs = array_map(fn ($p) => (float) $p['x'], $frame);
        $ys = array_map(fn ($p) => (float) $p['y'], $frame);

        $rect = new Rect(
            min($xs),
            min($ys),
            max($xs) - min($xs),
            max($ys) - min($ys),
        );

        try {
            return $this->centering->measure(new Rect(0.0, 0.0, 1.0, 1.0), $rect);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Centering from two dragged quads.
     *
     * The inner frame is mapped through the homography that flattens the card,
     * so the margins are measured on the card rather than in the photograph. It
     * matters: a card shot at an angle has a near edge that photographs wider
     * than the far one, and measuring margins in the photo would read that
     * perspective as a centering fault.
     *
     * The mapped frame is then squared off to its bounding box, because
     * CenteringMeasurer compares rectangles. A frame so skewed that its bounding
     * box misrepresents it means the guides were dragged wrong, and the
     * rectified image on screen will show that.
     *
     * @param  array<int, array{0: float, 1: float}>|null  $outline
     * @param  array<int, array{0: float, 1: float}>|null  $frame
     */
    private function centeringFromGuides(?array $outline, ?array $frame): ?Centering
    {
        if ($outline === null || $frame === null) {
            return null;
        }

        // The card, flattened to a unit square, clockwise from the top left.
        $toCard = Homography::between($outline, [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]]);

        $xs = [];
        $ys = [];

        foreach ($frame as [$x, $y]) {
            [$cx, $cy] = $toCard->apply($x, $y);
            $xs[] = $cx;
            $ys[] = $cy;
        }

        $rect = new Rect(
            min($xs),
            min($ys),
            max($xs) - min($xs),
            max($ys) - min($ys),
        );

        try {
            return $this->centering->measure(new Rect(0.0, 0.0, 1.0, 1.0), $rect);
        } catch (InvalidArgumentException) {
            // The frame landed outside the card, or flush to an edge. That is a
            // misdragged guide, and a bogus ratio is worse than none.
            return null;
        }
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
