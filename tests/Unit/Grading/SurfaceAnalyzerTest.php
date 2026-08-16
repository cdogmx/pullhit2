<?php

use App\Support\Grading\SurfaceAnalyzer;

/**
 * Validates the tilt-sequence surface pipeline against synthetic frames where we
 * know the ground truth, so the maths can be trusted before any camera work.
 *
 * The synthetic card is deliberately hostile. Its "artwork" contains hard stripe
 * edges, a filled panel, and — most importantly — a long diagonal LINE. That line
 * is elongated, high-contrast and looks exactly like a scratch; the only thing
 * separating it from real damage is that it does not change as the light moves.
 * An implementation that reports it is broken, so every clean-card assertion here
 * is really a test that artwork cancels.
 */
const SURF_W = 160;
const SURF_H = 220;

/** Constant printed artwork — never varies between frames. */
function surfArtworkAt(int $x, int $y): float
{
    $v = 30.0;
    $v += (intdiv($x, 20) % 2 === 0) ? 50.0 : 0.0;          // stripe edges
    $v += ($x > 40 && $x < 110 && $y > 60 && $y < 160) ? 35.0 : 0.0;  // panel
    $v += (abs($x - $y) < 2) ? 45.0 : 0.0;                  // the decoy diagonal

    return $v;
}

/** A broad specular band; $t in 0..1 sweeps it across the card. */
function surfHighlightAt(int $x, float $t): float
{
    $centre = $t * SURF_W;
    $sigma = SURF_W / 5.0;

    return 55.0 * exp(-(($x - $centre) ** 2) / (2 * $sigma ** 2));
}

/** Distance from a point to a segment — used to paint the scratch. */
function surfDistToSegment(float $px, float $py, array $a, array $b): float
{
    [$ax, $ay] = $a;
    [$bx, $by] = $b;
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $len2 = $dx * $dx + $dy * $dy;
    $t = $len2 > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $len2)) : 0;

    return sqrt(($px - ($ax + $t * $dx)) ** 2 + ($py - ($ay + $t * $dy)) ** 2);
}

/**
 * Build a tilt sequence.
 *
 * @param  array|null  $scratch  [[x1,y1],[x2,y2]] or null for an undamaged card
 * @param  bool  $sweep  false = the light never moved (a useless capture)
 * @param  float  $jitter  per-frame misalignment in pixels
 * @return array<int, array<int, float>>
 */
function surfTiltSequence(?array $scratch = null, int $frames = 4, bool $sweep = true, float $jitter = 0.0): array
{
    $out = [];

    for ($i = 0; $i < $frames; $i++) {
        $t = $sweep ? ($i + 0.5) / $frames : 0.5;
        $shift = (int) round($jitter * $i);
        $frame = [];

        for ($y = 0; $y < SURF_H; $y++) {
            for ($x = 0; $x < SURF_W; $x++) {
                $light = surfHighlightAt($x, $t);
                $v = surfArtworkAt(min(SURF_W - 1, $x + $shift), $y) + $light;

                // A scratch is geometry: it only shows where the highlight lands.
                if ($scratch !== null && surfDistToSegment($x, $y, $scratch[0], $scratch[1]) < 1.0) {
                    $v += 0.5 * $light;
                }

                $frame[] = min(255.0, $v);
            }
        }

        $out[] = $frame;
    }

    return $out;
}

test('a scratch invisible in any single frame is recovered from the sequence', function () {
    $scratch = [[30, 180], [130, 150]];
    $analysis = (new SurfaceAnalyzer)->analyze(surfTiltSequence($scratch), SURF_W, SURF_H);

    expect($analysis->isUsable())->toBeTrue()
        ->and($analysis->defects)->not->toBeEmpty();

    // The strongest defect should sit on the scratch we painted (midpoint ~80,165).
    $found = $analysis->defects[0];
    expect(surfDistToSegment($found->x, $found->y, $scratch[0], $scratch[1]))->toBeLessThan(12.0)
        ->and($found->elongation)->toBeGreaterThan(2.5)
        ->and($found->length)->toBeGreaterThan(40.0);
});

test('an undamaged card reports clean — the decoy diagonal is not a scratch', function () {
    $analysis = (new SurfaceAnalyzer)->analyze(surfTiltSequence(null), SURF_W, SURF_H);

    // The artwork's diagonal line is elongated and high-contrast, but constant
    // across frames, so it must cancel in max-min and never reach the report.
    expect($analysis->defects)->toBeEmpty()
        ->and($analysis->bucket)->toBe('clean')
        ->and($analysis->isUsable())->toBeTrue();
});

test('a capture where the light never moved is reported unusable, not clean', function () {
    $analysis = (new SurfaceAnalyzer)->analyze(surfTiltSequence(null, sweep: false), SURF_W, SURF_H);

    // Every frame is the same photo: nothing to difference. Saying "looks clean"
    // here would be the single most dangerous thing this pipeline could do.
    expect($analysis->isUsable())->toBeFalse()
        ->and($analysis->specularRange)->toBeLessThan(8.0);
});

test('a single photo is refused outright', function () {
    expect(fn () => (new SurfaceAnalyzer)->analyze([array_fill(0, SURF_W * SURF_H, 100.0)], SURF_W, SURF_H))
        ->toThrow(InvalidArgumentException::class);
});

test('mismatched frame sizes are refused', function () {
    expect(fn () => (new SurfaceAnalyzer)->analyze(
        [array_fill(0, SURF_W * SURF_H, 100.0), array_fill(0, 10, 100.0)], SURF_W, SURF_H,
    ))->toThrow(InvalidArgumentException::class);
});

test('severity scales with how much scratching there is', function () {
    $analyzer = new SurfaceAnalyzer;

    $light = $analyzer->analyze(surfTiltSequence([[60, 100], [80, 110]]), SURF_W, SURF_H);
    $heavy = $analyzer->analyze(surfTiltSequence([[10, 200], [150, 40]]), SURF_W, SURF_H);

    expect($heavy->score)->toBeLessThanOrEqual($light->score);
});

test('misalignment is the failure mode: a shifted frame leaks artwork as damage', function () {
    // Documents the tolerance rather than asserting the method is immune. Frames
    // must be warped onto a common rectangle before they reach the analyzer; at
    // a whole-pixel-per-frame drift the artwork stops cancelling.
    $clean = (new SurfaceAnalyzer)->analyze(surfTiltSequence(null, jitter: 0.0), SURF_W, SURF_H);
    $drifted = (new SurfaceAnalyzer)->analyze(surfTiltSequence(null, jitter: 1.0), SURF_W, SURF_H);

    expect($clean->defects)->toBeEmpty()
        ->and(count($drifted->defects))->toBeGreaterThan(count($clean->defects));
});
