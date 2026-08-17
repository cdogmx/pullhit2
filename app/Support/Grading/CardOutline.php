<?php

namespace App\Support\Grading;

use RuntimeException;

/**
 * Finds the four corners of a card in a photo, for the diagnostic harness.
 *
 * DELIBERATELY SIMPLE, and not the production detector. It assumes the test shot
 * the harness asks for: one card, filling a good part of the frame, against a
 * plainly contrasting background. Production detection already exists client-side
 * in resources/js/lib/card-detect.ts and handles the messy cases (playmats, other
 * objects in frame, two cards) that this does not.
 *
 * Method: Otsu threshold, largest connected component of whichever polarity gives
 * a card-shaped blob, then the four extreme points of that blob — a rotated
 * rectangle's corners are exactly the extremes of x+y and x−y.
 */
class CardOutline
{
    /** A card is 2.5 x 3.5; accept a generous band around that either way up. */
    private const MIN_ASPECT = 1.15;

    private const MAX_ASPECT = 1.75;

    /**
     * @param  array<int, float>  $luma  row-major luminance, 0–255
     * @return array<int, array{0: float, 1: float}> corners clockwise from top-left
     *
     * @throws RuntimeException when nothing card-shaped is found
     */
    public function detect(array $luma, int $width, int $height): array
    {
        $cut = $this->otsu($luma);

        $best = null;
        foreach ([true, false] as $bright) {
            $mask = [];
            foreach ($luma as $p => $v) {
                $mask[$p] = $bright ? $v > $cut : $v <= $cut;
            }

            $component = $this->largestComponent($mask, $width, $height);
            if ($component === []) {
                continue;
            }

            $corners = $this->extremes($component, $width);
            $aspect = $this->aspectOf($corners);

            if ($aspect < self::MIN_ASPECT || $aspect > self::MAX_ASPECT) {
                continue;
            }

            // Prefer the larger plausible blob — the card, not a highlight on it.
            if ($best === null || count($component) > $best['size']) {
                $best = ['corners' => $corners, 'size' => count($component)];
            }
        }

        if ($best === null) {
            throw new RuntimeException(
                'No card-shaped region found. Shoot one card against a plain, contrasting background, '.
                'filling most of the frame — or pass --corners to specify them by hand.',
            );
        }

        return $best['corners'];
    }

    /** Long side over short side of the detected quad. */
    private function aspectOf(array $c): float
    {
        $top = hypot($c[1][0] - $c[0][0], $c[1][1] - $c[0][1]);
        $left = hypot($c[3][0] - $c[0][0], $c[3][1] - $c[0][1]);

        $long = max($top, $left);
        $short = max(1.0, min($top, $left));

        return $long / $short;
    }

    /**
     * Corners of a rotated rectangle from its pixel set: the extremes of x+y give
     * the top-left and bottom-right, the extremes of x−y the other two.
     *
     * @param  array<int, int>  $component
     * @return array<int, array{0: float, 1: float}>
     */
    private function extremes(array $component, int $width): array
    {
        $minSum = PHP_INT_MAX;
        $maxSum = PHP_INT_MIN;
        $minDiff = PHP_INT_MAX;
        $maxDiff = PHP_INT_MIN;
        $tl = $br = $bl = $tr = [0.0, 0.0];

        foreach ($component as $p) {
            $x = $p % $width;
            $y = intdiv($p, $width);

            if ($x + $y < $minSum) {
                $minSum = $x + $y;
                $tl = [(float) $x, (float) $y];
            }
            if ($x + $y > $maxSum) {
                $maxSum = $x + $y;
                $br = [(float) $x, (float) $y];
            }
            if ($x - $y < $minDiff) {
                $minDiff = $x - $y;
                $bl = [(float) $x, (float) $y];
            }
            if ($x - $y > $maxDiff) {
                $maxDiff = $x - $y;
                $tr = [(float) $x, (float) $y];
            }
        }

        return [$tl, $tr, $br, $bl];
    }

    /** Otsu's method — the threshold minimising within-class variance. */
    private function otsu(array $luma): float
    {
        $hist = array_fill(0, 256, 0);
        foreach ($luma as $v) {
            $hist[max(0, min(255, (int) round($v)))]++;
        }

        $total = count($luma);
        $sum = 0.0;
        for ($i = 0; $i < 256; $i++) {
            $sum += $i * $hist[$i];
        }

        $sumB = 0.0;
        $wB = 0;
        $best = 0.0;
        $cut = 128.0;

        for ($t = 0; $t < 256; $t++) {
            $wB += $hist[$t];
            if ($wB === 0) {
                continue;
            }
            $wF = $total - $wB;
            if ($wF === 0) {
                break;
            }

            $sumB += $t * $hist[$t];
            $between = $wB * $wF * (($sumB / $wB) - (($sum - $sumB) / $wF)) ** 2;

            if ($between > $best) {
                $best = $between;
                $cut = (float) $t;
            }
        }

        return $cut;
    }

    /**
     * Largest 4-connected component, iteratively.
     *
     * @return array<int, int>
     */
    private function largestComponent(array $mask, int $width, int $height): array
    {
        $seen = [];
        $best = [];
        $size = $width * $height;

        for ($start = 0; $start < $size; $start++) {
            if (! $mask[$start] || isset($seen[$start])) {
                continue;
            }

            $stack = [$start];
            $seen[$start] = true;
            $component = [];

            while ($stack !== []) {
                $p = array_pop($stack);
                $component[] = $p;

                $x = $p % $width;
                $y = intdiv($p, $width);

                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    if ($nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                        continue;
                    }
                    $q = $ny * $width + $nx;
                    if ($mask[$q] && ! isset($seen[$q])) {
                        $seen[$q] = true;
                        $stack[] = $q;
                    }
                }
            }

            if (count($component) > count($best)) {
                $best = $component;
            }
        }

        return $best;
    }
}
