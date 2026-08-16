<?php

use App\Support\Grading\FrameWarper;
use App\Support\Grading\Homography;
use App\Support\Grading\SurfaceAnalyzer;

/**
 * The warp exists to serve the surface pipeline, so the test that counts is the
 * end-to-end one: a clean card shot four times with the phone at a slightly
 * different angle each time must come back CLEAN, not covered in false scratches.
 * Everything above it is scaffolding for that.
 */

/** The card as it truly is, in its own flat coordinate space. */
function warpCanonicalCard(int $w, int $h, float $t, ?array $scratch): array
{
    $out = [];
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $v = 30.0;
            $v += (intdiv($x, 14) % 2 === 0) ? 50.0 : 0.0;
            $v += ($x > $w * 0.25 && $x < $w * 0.7 && $y > $h * 0.3 && $y < $h * 0.7) ? 35.0 : 0.0;
            $v += (abs($x - $y) < 2) ? 45.0 : 0.0;          // decoy diagonal again

            $light = 55.0 * exp(-(($x - $t * $w) ** 2) / (2 * ($w / 5.0) ** 2));
            $v += $light;

            if ($scratch !== null && warpPointOnSegment($x, $y, $scratch)) {
                $v += 0.5 * $light;
            }

            $out[] = min(255.0, $v);
        }
    }

    return $out;
}

function warpPointOnSegment(float $px, float $py, array $seg): bool
{
    [[$ax, $ay], [$bx, $by]] = $seg;
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $l2 = $dx * $dx + $dy * $dy;
    $t = $l2 > 0 ? max(0, min(1, (($px - $ax) * $dx + ($py - $ay) * $dy) / $l2)) : 0;

    return hypot($px - ($ax + $t * $dx), $py - ($ay + $t * $dy)) < 1.0;
}

function warpBilinear(array $img, int $w, int $h, float $x, float $y): float
{
    if ($x < 0 || $y < 0 || $x > $w - 1 || $y > $h - 1) {
        return 12.0;    // background outside the card
    }
    $x0 = (int) floor($x);
    $y0 = (int) floor($y);
    $x1 = min($w - 1, $x0 + 1);
    $y1 = min($h - 1, $y0 + 1);
    $fx = $x - $x0;
    $fy = $y - $y0;

    return ($img[$y0 * $w + $x0] * (1 - $fx) + $img[$y0 * $w + $x1] * $fx) * (1 - $fy)
         + ($img[$y1 * $w + $x0] * (1 - $fx) + $img[$y1 * $w + $x1] * $fx) * $fy;
}

/** Render the canonical card into a photo, seen through the given quad. */
function warpRenderPhoto(array $card, int $cw, int $ch, array $quad, int $pw, int $ph): array
{
    $toCard = Homography::between($quad, [[0, 0], [$cw - 1, 0], [$cw - 1, $ch - 1], [0, $ch - 1]]);
    $out = [];

    for ($y = 0; $y < $ph; $y++) {
        for ($x = 0; $x < $pw; $x++) {
            [$cx, $cy] = $toCard->apply((float) $x, (float) $y);
            $out[] = warpBilinear($card, $cw, $ch, $cx, $cy);
        }
    }

    return $out;
}

test('a homography maps its four corners exactly onto their targets', function () {
    $from = [[0, 0], [100, 0], [100, 140], [0, 140]];
    $to = [[12, 7], [96, 15], [104, 150], [3, 138]];

    $h = Homography::between($from, $to);

    foreach ($from as $i => [$x, $y]) {
        [$u, $v] = $h->apply($x, $y);
        expect($u)->toBeGreaterThan($to[$i][0] - 0.001)->toBeLessThan($to[$i][0] + 0.001)
            ->and($v)->toBeGreaterThan($to[$i][1] - 0.001)->toBeLessThan($to[$i][1] + 0.001);
    }
});

test('the inverse undoes the transform', function () {
    $h = Homography::between(
        [[0, 0], [100, 0], [100, 140], [0, 140]],
        [[12, 7], [96, 15], [104, 150], [3, 138]],
    );

    [$x, $y] = $h->apply(37.0, 91.0);
    [$bx, $by] = $h->inverse()->apply($x, $y);

    expect(round($bx, 4))->toBe(37.0)->and(round($by, 4))->toBe(91.0);
});

test('collinear corners are refused rather than silently producing nonsense', function () {
    expect(fn () => Homography::between(
        [[0, 0], [10, 0], [20, 0], [30, 0]],
        [[0, 0], [10, 0], [20, 0], [30, 0]],
    ))->toThrow(InvalidArgumentException::class);
});

test('warping a tilted photo recovers the flat card', function () {
    $cw = 60;
    $ch = 84;
    $card = warpCanonicalCard($cw, $ch, 0.5, null);

    $quad = [[18, 11], [150, 25], [143, 210], [11, 196]];
    $photo = warpRenderPhoto($card, $cw, $ch, $quad, 180, 240);

    $back = (new FrameWarper)->warp($photo, 180, 240, $quad, $cw, $ch);

    // Compare interiors only — resampling softens the outermost ring.
    $diff = [];
    for ($y = 4; $y < $ch - 4; $y++) {
        for ($x = 4; $x < $cw - 4; $x++) {
            $diff[] = abs($back[$y * $cw + $x] - $card[$y * $cw + $x]);
        }
    }

    expect(array_sum($diff) / count($diff))->toBeLessThan(6.0);
});

test('rectifying a sequence puts every frame on one canvas', function () {
    $cw = 50;
    $ch = 70;
    $card = warpCanonicalCard($cw, $ch, 0.5, null);

    $quads = [
        [[10, 10], [120, 12], [118, 170], [12, 168]],
        [[14, 8], [124, 18], [115, 175], [9, 165]],
    ];
    $frames = array_map(fn ($q) => warpRenderPhoto($card, $cw, $ch, $q, 150, 200), $quads);

    $result = (new FrameWarper)->rectifySequence($frames, 150, 200, $quads);

    expect($result['frames'])->toHaveCount(2)
        ->and($result['height'])->toBe((int) round($result['width'] * FrameWarper::ASPECT))
        ->and(count($result['frames'][0]))->toBe($result['width'] * $result['height']);
});

test('warping is what lets a hand-held clean card come back clean', function () {
    $cw = 120;
    $ch = 168;

    // Four shots: the light sweeps across AND the phone moves between each.
    $quads = [
        [[20, 14], [196, 20], [192, 268], [16, 262]],
        [[24, 11], [200, 26], [186, 272], [13, 256]],
        [[18, 18], [193, 15], [197, 264], [21, 270]],
        [[22, 16], [198, 22], [190, 270], [15, 259]],
    ];

    $photos = [];
    foreach ($quads as $i => $quad) {
        $card = warpCanonicalCard($cw, $ch, ($i + 0.5) / 4, null);   // undamaged
        $photos[] = warpRenderPhoto($card, $cw, $ch, $quad, 220, 290);
    }

    $analyzer = new SurfaceAnalyzer;

    // Straight off the camera the card sits somewhere different in every frame,
    // so the printing does not cancel and reads as damage.
    $unaligned = $analyzer->analyze($photos, 220, 290);

    // Rectified onto a common canvas, the printing cancels and the card is clean.
    $rectified = (new FrameWarper)->rectifySequence($photos, 220, 290, $quads, $cw);
    $aligned = $analyzer->analyze($rectified['frames'], $rectified['width'], $rectified['height']);

    expect(count($unaligned->defects))->toBeGreaterThan(0)
        ->and($aligned->defects)->toBeEmpty()
        ->and($aligned->bucket)->toBe('clean');
});

test('a real scratch still survives the warp', function () {
    $cw = 120;
    $ch = 168;
    $scratch = [[25, 130], [100, 110]];

    $quads = [
        [[20, 14], [196, 20], [192, 268], [16, 262]],
        [[24, 11], [200, 26], [186, 272], [13, 256]],
        [[18, 18], [193, 15], [197, 264], [21, 270]],
        [[22, 16], [198, 22], [190, 270], [15, 259]],
    ];

    $photos = [];
    foreach ($quads as $i => $quad) {
        $card = warpCanonicalCard($cw, $ch, ($i + 0.5) / 4, $scratch);
        $photos[] = warpRenderPhoto($card, $cw, $ch, $quad, 220, 290);
    }

    $rectified = (new FrameWarper)->rectifySequence($photos, 220, 290, $quads, $cw);
    $analysis = (new SurfaceAnalyzer)->analyze($rectified['frames'], $rectified['width'], $rectified['height']);

    expect($analysis->defects)->not->toBeEmpty()
        ->and($analysis->bucket)->not->toBe('clean');
});
