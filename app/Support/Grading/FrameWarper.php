<?php

namespace App\Support\Grading;

use InvalidArgumentException;

/**
 * Rectifies hand-held frames onto a common canvas so they can be compared
 * pixel-for-pixel — the step {@see SurfaceAnalyzer} depends on and refuses to
 * paper over.
 *
 * Each frame arrives with the four card corners the detector found in it. We map
 * those onto the same canonical rectangle, so every output frame shows the card
 * in exactly the same place at exactly the same scale, whatever the phone was
 * doing. Sampling is bilinear rather than nearest-neighbour: nearest-neighbour
 * quantises each frame's sampling grid differently, and that difference alone
 * shows up in max-min as false "damage".
 *
 * Pixel work in PHP is slow, so in production this belongs in the browser
 * (canvas/WebGL) — the coefficients are the part worth pinning down here, and
 * they port directly.
 */
class FrameWarper
{
    /** A trading card is 2.5" x 3.5", so the canvas keeps that aspect. */
    public const ASPECT = 3.5 / 2.5;

    /**
     * @param  array<int, float>  $frame  source luminance, row-major
     * @param  array<int, array{0: float, 1: float}>  $corners  clockwise from top-left, in source pixels
     * @return array<int, float> warped luminance, $outWidth x $outHeight
     */
    public function warp(array $frame, int $srcWidth, int $srcHeight, array $corners, int $outWidth, int $outHeight): array
    {
        if (count($frame) !== $srcWidth * $srcHeight) {
            throw new InvalidArgumentException('Frame does not match the given source dimensions.');
        }

        // Solve destination -> source directly, so every output pixel has exactly
        // one place to read from (the forward direction would leave holes).
        $canvas = [
            [0.0, 0.0],
            [$outWidth - 1.0, 0.0],
            [$outWidth - 1.0, $outHeight - 1.0],
            [0.0, $outHeight - 1.0],
        ];

        $toSource = Homography::between($canvas, $corners);

        $out = array_fill(0, $outWidth * $outHeight, 0.0);

        for ($y = 0; $y < $outHeight; $y++) {
            for ($x = 0; $x < $outWidth; $x++) {
                [$sx, $sy] = $toSource->apply((float) $x, (float) $y);
                $out[$y * $outWidth + $x] = $this->sample($frame, $srcWidth, $srcHeight, $sx, $sy);
            }
        }

        return $out;
    }

    /**
     * Warp a whole sequence onto one canvas sized from the first frame's card.
     *
     * @param  array<int, array<int, float>>  $frames
     * @param  array<int, array<int, array{0: float, 1: float}>>  $cornersPerFrame
     * @return array{frames: array<int, array<int, float>>, width: int, height: int}
     */
    public function rectifySequence(array $frames, int $srcWidth, int $srcHeight, array $cornersPerFrame, int $outWidth = 0): array
    {
        $frames = array_values($frames);
        $cornersPerFrame = array_values($cornersPerFrame);

        if (count($frames) !== count($cornersPerFrame)) {
            throw new InvalidArgumentException('Every frame needs its own set of corners.');
        }

        if ($frames === []) {
            throw new InvalidArgumentException('No frames to rectify.');
        }

        $outWidth = $outWidth > 0 ? $outWidth : $this->canvasWidth($cornersPerFrame[0]);
        $outHeight = (int) round($outWidth * self::ASPECT);

        $warped = [];
        foreach ($frames as $i => $frame) {
            $warped[] = $this->warp($frame, $srcWidth, $srcHeight, $cornersPerFrame[$i], $outWidth, $outHeight);
        }

        return ['frames' => $warped, 'width' => $outWidth, 'height' => $outHeight];
    }

    /** Canvas width from the card's widest observed edge — never upscale detail we don't have. */
    private function canvasWidth(array $corners): int
    {
        $top = hypot($corners[1][0] - $corners[0][0], $corners[1][1] - $corners[0][1]);
        $bottom = hypot($corners[2][0] - $corners[3][0], $corners[2][1] - $corners[3][1]);

        return max(32, (int) round(max($top, $bottom)));
    }

    /**
     * Bilinear sample, clamped at the border. Out-of-frame reads return the edge
     * value rather than black, so a corner slightly outside the photo does not
     * paint a hard false edge into the warped result.
     */
    private function sample(array $frame, int $width, int $height, float $x, float $y): float
    {
        $x = max(0.0, min($width - 1.0, $x));
        $y = max(0.0, min($height - 1.0, $y));

        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $x1 = min($width - 1, $x0 + 1);
        $y1 = min($height - 1, $y0 + 1);

        $fx = $x - $x0;
        $fy = $y - $y0;

        $tl = $frame[$y0 * $width + $x0];
        $tr = $frame[$y0 * $width + $x1];
        $bl = $frame[$y1 * $width + $x0];
        $br = $frame[$y1 * $width + $x1];

        return ($tl * (1 - $fx) + $tr * $fx) * (1 - $fy)
             + ($bl * (1 - $fx) + $br * $fx) * $fy;
    }
}
