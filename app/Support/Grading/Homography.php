<?php

namespace App\Support\Grading;

use InvalidArgumentException;

/**
 * The 3x3 projective transform that maps one quadrilateral onto another — the
 * maths behind flattening a hand-held photo of a card onto a canonical rectangle.
 *
 * Needed twice over. Centering is only meaningful on a rectified card (shoot the
 * same well-centred card at a tilt and the near edge measures wider), and the
 * surface pipeline cannot difference frames at all unless they are registered to
 * each other first — misalignment is its documented failure mode.
 *
 * Solved as the usual 8-unknown linear system (h33 fixed at 1) by Gaussian
 * elimination with partial pivoting. Four correspondences, eight equations.
 */
readonly class Homography
{
    /** @param array<int, float> $m row-major 3x3, nine entries */
    public function __construct(public array $m)
    {
        if (count($m) !== 9) {
            throw new InvalidArgumentException('A homography needs nine coefficients.');
        }
    }

    /**
     * Build the transform taking the four $from points to the four $to points,
     * each given clockwise from the top-left as [x, y].
     *
     * @param  array<int, array{0: float, 1: float}>  $from
     * @param  array<int, array{0: float, 1: float}>  $to
     */
    public static function between(array $from, array $to): self
    {
        if (count($from) !== 4 || count($to) !== 4) {
            throw new InvalidArgumentException('A homography needs exactly four point pairs.');
        }

        $a = [];
        $b = [];

        foreach ($from as $i => [$x, $y]) {
            [$u, $v] = $to[$i];

            $a[] = [$x, $y, 1, 0, 0, 0, -$u * $x, -$u * $y];
            $b[] = $u;
            $a[] = [0, 0, 0, $x, $y, 1, -$v * $x, -$v * $y];
            $b[] = $v;
        }

        $h = self::solve($a, $b);
        $h[] = 1.0;

        return new self($h);
    }

    /**
     * Apply the transform to a point.
     *
     * @return array{0: float, 1: float}
     */
    public function apply(float $x, float $y): array
    {
        $m = $this->m;
        $w = $m[6] * $x + $m[7] * $y + $m[8];

        if (abs($w) < 1e-12) {
            throw new InvalidArgumentException('Point maps to infinity under this homography.');
        }

        return [
            ($m[0] * $x + $m[1] * $y + $m[2]) / $w,
            ($m[3] * $x + $m[4] * $y + $m[5]) / $w,
        ];
    }

    /** The transform that undoes this one. */
    public function inverse(): self
    {
        [$a, $b, $c, $d, $e, $f, $g, $h, $i] = $this->m;

        $det = $a * ($e * $i - $f * $h) - $b * ($d * $i - $f * $g) + $c * ($d * $h - $e * $g);

        if (abs($det) < 1e-12) {
            throw new InvalidArgumentException('Homography is singular and cannot be inverted.');
        }

        return new self(array_map(fn ($v) => $v / $det, [
            $e * $i - $f * $h, $c * $h - $b * $i, $b * $f - $c * $e,
            $f * $g - $d * $i, $a * $i - $c * $g, $c * $d - $a * $f,
            $d * $h - $e * $g, $b * $g - $a * $h, $a * $e - $b * $d,
        ]));
    }

    /**
     * Gaussian elimination with partial pivoting.
     *
     * @param  array<int, array<int, float>>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    private static function solve(array $a, array $b): array
    {
        $n = count($b);

        for ($col = 0; $col < $n; $col++) {
            // Pivot on the largest remaining magnitude for numerical stability.
            $pivot = $col;
            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($a[$row][$col]) > abs($a[$pivot][$col])) {
                    $pivot = $row;
                }
            }

            if (abs($a[$pivot][$col]) < 1e-12) {
                throw new InvalidArgumentException('Degenerate point set — the four corners are collinear or coincident.');
            }

            [$a[$col], $a[$pivot]] = [$a[$pivot], $a[$col]];
            [$b[$col], $b[$pivot]] = [$b[$pivot], $b[$col]];

            for ($row = 0; $row < $n; $row++) {
                if ($row === $col) {
                    continue;
                }
                $factor = $a[$row][$col] / $a[$col][$col];
                for ($k = $col; $k < $n; $k++) {
                    $a[$row][$k] -= $factor * $a[$col][$k];
                }
                $b[$row] -= $factor * $b[$col];
            }
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $b[$i] / $a[$i][$i];
        }

        return $out;
    }
}
