<?php

namespace App\Support\Grading;

use InvalidArgumentException;

/**
 * EXPERIMENTAL — NOT WIRED INTO THE GRADE ESTIMATE. Reached by the
 * grading:surface harness only. The condition estimator deliberately treats
 * surface as unseen and says so; see ConditionEstimate::caveats().
 *
 * It works on synthetic frames and fails on realistic ones. A synthetic card
 * carrying ordinary texture produced 154 false defects against a single real
 * scratch, even given ground-truth corners. The cause is not a tuning problem:
 * no resampler is phase-invariant near Nyquist, so two frames sampled at
 * different sub-pixel offsets reconstruct fine artwork differently, leaving a
 * residual of about |gradient| x offset. Real card printing — fine text, line
 * art, holo patterns — has gradients everywhere, and that residual swamps the
 * damage. Discounting by local artwork energy (see $textureWeight) only moved
 * 154 to 138; shrinking the canvas helps only by throwing away the resolution
 * the scratches live in.
 *
 * Kept because the approach is sound and the failure is well characterised: with
 * a real capture rig, or a phone that exposes per-frame sub-pixel registration,
 * this becomes viable. Do not put it in front of users before then.
 *
 * ---
 *
 * Recovers surface damage from a tilt sequence — several photos of the same card
 * with the specular highlight swept across it.
 *
 * The physics: a scratch is a geometry defect, not an ink defect. It is invisible
 * under flat diffuse light and only shows when a highlight crosses it, which is
 * why collectors tilt a card under a lamp instead of staring at it flat. TAG
 * solves this with photometric stereo — lights at known angles, true surface
 * normals. We cannot control the light, but we do not have to: we only need the
 * highlight to MOVE while the printed artwork stays put.
 *
 *   min across frames  ≈ albedo    (the print, with the highlight removed)
 *   max − min          ≈ specular  (everything that changed as the light moved)
 *
 * The artwork is constant, so it cancels in the difference; the highlight and
 * anything it reveals do not. High-passing the specular map then drops the broad,
 * smooth glare envelope and leaves the fine linear structure that scratches are.
 *
 * Operates on plain luminance arrays rather than image resources so the maths is
 * testable against synthetic frames. In production this same pipeline should run
 * in the browser (canvas/WebGL) — this box has GD only, no Imagick, and per-pixel
 * work in PHP on a multi-megapixel card is far too slow to serve.
 *
 * IMPORTANT: frames must already be warped onto a common rectangle. Misalignment
 * is this method's main failure mode — artwork that does not cancel leaks into
 * the specular map and reads as damage. See the test for the measured tolerance.
 */
class SurfaceAnalyzer
{
    public function __construct(
        /** Box-blur radius used to estimate (and subtract) the smooth glare envelope. */
        private int $blurRadius = 6,
        /** Threshold on the high-passed map, in standard deviations above its mean. */
        private float $threshold = 3.0,
        /** Minimum pixels for a component to be considered at all. */
        private int $minPixels = 6,
        /** Minimum elongation (major/minor axis) for a component to count as a scratch. */
        private float $minElongation = 2.5,
        /**
         * Fraction of each dimension ignored around the border. Two reasons, and
         * both are real rather than a fudge: surface means the card FACE — damage
         * at the perimeter is what the edges and corners attributes are for, and
         * TAG scores them separately for the same reason. And a rectified frame's
         * outermost ring is bilinearly mixed with whatever lay outside the card,
         * in slightly different proportions per frame, so it varies across the
         * sequence and reads as a long thin scratch pinned to the edge.
         */
        private float $borderInset = 0.02,
        /**
         * How hard to discount detail that sits on busy printing.
         *
         * No resampler is phase-invariant near Nyquist: two frames sampled at
         * different sub-pixel offsets reconstruct fine artwork slightly
         * differently, leaving a residual of roughly |gradient| x offset. Fine
         * text, line art and holo patterns therefore do NOT cancel, and on a real
         * card they swamp the damage — a synthetic card with ordinary texture
         * produced 154 false defects before this existed.
         *
         * A scratch's signal, by contrast, is a change in the reflected light and
         * carries no dependence on what is printed underneath. So dividing the
         * detail by local albedo energy targets exactly the confound: sensitivity
         * stays high on flat borders and drops where the printing is loud, which
         * is the honest trade rather than a global threshold that loses both.
         */
        private float $textureWeight = 0.05,
    ) {}

    /**
     * The intermediate maps, exposed so a diagnostic harness can render them —
     * eyeballing the albedo and detail images is the only practical way to tell
     * whether a real capture carried usable signal.
     *
     * @param  array<int, array<int, float>>  $frames  aligned luminance maps (0–255), row-major
     * @return array{min: array<int, float>, specular: array<int, float>, detail: array<int, float>, range: float}
     */
    public function composites(array $frames, int $width, int $height): array
    {
        $frames = array_values($frames);
        $n = count($frames);
        $size = $width * $height;

        if ($n < 2) {
            throw new InvalidArgumentException('Surface analysis needs at least two frames; one photo carries no specular information.');
        }

        foreach ($frames as $f) {
            if (count($f) !== $size) {
                throw new InvalidArgumentException('All frames must be the same size as the given dimensions.');
            }
        }

        // 1) Per-pixel min (albedo) and max across the sequence.
        $min = $frames[0];
        $max = $frames[0];
        for ($i = 1; $i < $n; $i++) {
            $f = $frames[$i];
            for ($p = 0; $p < $size; $p++) {
                if ($f[$p] < $min[$p]) {
                    $min[$p] = $f[$p];
                }
                if ($f[$p] > $max[$p]) {
                    $max[$p] = $f[$p];
                }
            }
        }

        // 2) Specular component — what changed as the light moved.
        $specular = [];
        for ($p = 0; $p < $size; $p++) {
            $specular[$p] = $max[$p] - $min[$p];
        }

        // 3) High-pass: subtract the smooth glare envelope, keep the fine detail.
        $blurred = $this->boxBlur($specular, $width, $height, $this->blurRadius);
        $detail = [];
        for ($p = 0; $p < $size; $p++) {
            $detail[$p] = $specular[$p] - $blurred[$p];
        }

        // 4) Discount detail sitting on loud printing — see $textureWeight. The
        //    energy map is blurred because the resampling residual lands beside
        //    an edge, not exactly on it.
        $energy = $this->boxBlur($this->gradient($min, $width, $height), $width, $height, 2);

        $normalized = [];
        for ($p = 0; $p < $size; $p++) {
            $normalized[$p] = $detail[$p] / (1.0 + $this->textureWeight * $energy[$p]);
        }

        return [
            'min' => $min,
            'specular' => $specular,
            'detail' => $detail,
            'normalized' => $normalized,
            // If the highlight never moved there is nothing to read; say so.
            'range' => $this->spread($specular),
        ];
    }

    /** L1 gradient magnitude by central differences — cheap and good enough here. */
    private function gradient(array $map, int $width, int $height): array
    {
        $out = array_fill(0, $width * $height, 0.0);

        for ($y = 1; $y < $height - 1; $y++) {
            for ($x = 1; $x < $width - 1; $x++) {
                $p = $y * $width + $x;
                $out[$p] = abs($map[$p + 1] - $map[$p - 1]) + abs($map[$p + $width] - $map[$p - $width]);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<int, float>>  $frames  aligned luminance maps (0–255), row-major
     */
    public function analyze(array $frames, int $width, int $height): SurfaceAnalysis
    {
        $size = $width * $height;
        $n = count($frames);

        ['normalized' => $detail, 'range' => $range] = $this->composites($frames, $width, $height);

        // 5) Threshold well above the noise floor.
        [$mean, $std] = $this->stats($detail);
        $cut = $mean + $this->threshold * $std;

        $insetX = max(2, (int) round($this->borderInset * $width));
        $insetY = max(2, (int) round($this->borderInset * $height));

        $mask = array_fill(0, $size, false);
        for ($y = $insetY; $y < $height - $insetY; $y++) {
            for ($x = $insetX; $x < $width - $insetX; $x++) {
                $p = $y * $width + $x;
                $mask[$p] = $detail[$p] > $cut;
            }
        }

        // 5) Keep only elongated components — scratches are lines, noise is blobs.
        $defects = [];
        foreach ($this->components($mask, $width, $height) as $component) {
            if (count($component) < $this->minPixels) {
                continue;
            }

            $shape = $this->shapeOf($component, $width);
            if ($shape['elongation'] < $this->minElongation) {
                continue;
            }

            $strength = 0.0;
            foreach ($component as $p) {
                $strength += $detail[$p];
            }

            $defects[] = new SurfaceDefect(
                x: $shape['cx'],
                y: $shape['cy'],
                length: $shape['length'],
                elongation: $shape['elongation'],
                strength: $strength / count($component),
            );
        }

        usort($defects, fn ($a, $b) => $b->length <=> $a->length);

        [$score, $bucket] = $this->grade($defects, $width, $height);

        return new SurfaceAnalysis($defects, $score, $bucket, $n, $range);
    }

    /**
     * Total defect length relative to the card's diagonal, bucketed. Coarse and
     * provisional — see {@see SurfaceAnalysis} for why it cannot be otherwise.
     *
     * @param  array<int, SurfaceDefect>  $defects
     * @return array{0: int, 1: string}
     */
    private function grade(array $defects, int $width, int $height): array
    {
        $diagonal = sqrt($width ** 2 + $height ** 2);
        $total = array_sum(array_map(fn (SurfaceDefect $d) => $d->length, $defects));
        $ratio = $diagonal > 0 ? $total / $diagonal : 0.0;

        return match (true) {
            $defects === [] => [995, 'clean'],
            $ratio < 0.35 => [950, 'light'],
            $ratio < 1.2 => [890, 'moderate'],
            default => [820, 'heavy'],
        };
    }

    /** Difference between the 95th and 5th percentile — robust stand-in for range. */
    private function spread(array $values): float
    {
        $sorted = $values;
        sort($sorted);
        $n = count($sorted);

        return $sorted[(int) ($n * 0.95)] - $sorted[(int) ($n * 0.05)];
    }

    /** @return array{0: float, 1: float} mean and standard deviation */
    private function stats(array $values): array
    {
        $n = count($values);
        $mean = array_sum($values) / $n;

        $var = 0.0;
        foreach ($values as $v) {
            $var += ($v - $mean) ** 2;
        }

        return [$mean, sqrt($var / $n)];
    }

    /**
     * Separable box blur via a running sum — O(pixels), independent of radius, so
     * the envelope estimate stays cheap even at a large radius.
     */
    private function boxBlur(array $src, int $width, int $height, int $radius): array
    {
        $tmp = array_fill(0, $width * $height, 0.0);
        $out = array_fill(0, $width * $height, 0.0);

        for ($y = 0; $y < $height; $y++) {
            $row = $y * $width;
            for ($x = 0; $x < $width; $x++) {
                $from = max(0, $x - $radius);
                $to = min($width - 1, $x + $radius);
                $acc = 0.0;
                for ($i = $from; $i <= $to; $i++) {
                    $acc += $src[$row + $i];
                }
                $tmp[$row + $x] = $acc / ($to - $from + 1);
            }
        }

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $from = max(0, $y - $radius);
                $to = min($height - 1, $y + $radius);
                $acc = 0.0;
                for ($i = $from; $i <= $to; $i++) {
                    $acc += $tmp[$i * $width + $x];
                }
                $out[$y * $width + $x] = $acc / ($to - $from + 1);
            }
        }

        return $out;
    }

    /**
     * 8-connected components of a boolean mask, iteratively (a card-sized mask
     * would blow the stack recursively).
     *
     * @return array<int, array<int, int>> component => pixel indices
     */
    private function components(array $mask, int $width, int $height): array
    {
        $seen = [];
        $out = [];

        for ($start = 0, $size = $width * $height; $start < $size; $start++) {
            if (! $mask[$start] || isset($seen[$start])) {
                continue;
            }

            $stack = [$start];
            $seen[$start] = true;
            $component = [];

            while ($stack !== []) {
                $p = array_pop($stack);
                $component[] = $p;

                $px = $p % $width;
                $py = intdiv($p, $width);

                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $px + $dx;
                        $ny = $py + $dy;
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
            }

            $out[] = $component;
        }

        return $out;
    }

    /**
     * Centroid, elongation and length of a pixel set, from the eigenvalues of its
     * coordinate covariance. A scratch is a line (one large eigenvalue, one tiny);
     * a dust speck or compression artefact is round (two similar eigenvalues).
     *
     * @param  array<int, int>  $component
     * @return array{cx: float, cy: float, elongation: float, length: float}
     */
    private function shapeOf(array $component, int $width): array
    {
        $n = count($component);
        $cx = $cy = 0.0;

        foreach ($component as $p) {
            $cx += $p % $width;
            $cy += intdiv($p, $width);
        }
        $cx /= $n;
        $cy /= $n;

        $sxx = $syy = $sxy = 0.0;
        foreach ($component as $p) {
            $dx = ($p % $width) - $cx;
            $dy = intdiv($p, $width) - $cy;
            $sxx += $dx * $dx;
            $syy += $dy * $dy;
            $sxy += $dx * $dy;
        }
        $sxx /= $n;
        $syy /= $n;
        $sxy /= $n;

        // Eigenvalues of the 2x2 covariance matrix.
        $trace = $sxx + $syy;
        $det = $sxx * $syy - $sxy * $sxy;
        $gap = sqrt(max(0.0, $trace * $trace / 4 - $det));
        $major = $trace / 2 + $gap;
        $minor = max(1e-6, $trace / 2 - $gap);

        return [
            'cx' => $cx,
            'cy' => $cy,
            'elongation' => sqrt($major / $minor),
            // ~4σ along the major axis approximates the visible extent of a line.
            'length' => 4 * sqrt($major),
        ];
    }
}
