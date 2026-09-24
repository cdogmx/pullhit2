<?php

namespace App\Support\Grading;

/**
 * Four corners, put in the order every transform here assumes.
 *
 * Homography::between maps its points positionally: the first goes to the top
 * left, the second to the top right, and so on. Hand it the same four corners
 * starting from a different one and the card comes out a quarter turn round —
 * not an error, just a picture that is wrong in a way the numbers cannot see.
 *
 * Nothing guarantees the order. A detector emits whatever its scan found first,
 * and a person dragging handles can take the top-left one past the top-right
 * without noticing they have done anything at all. So the order is established
 * here rather than assumed everywhere.
 */
class Quad
{
    /**
     * Clockwise from the top left.
     *
     * By sums and differences rather than by angle about the centre: the top
     * left is the corner nearest the origin (least x+y), the bottom right the
     * furthest, and the other two are separated by x−y. It is the same rule
     * CardOutline already picks its corners with, and it holds for any card
     * tilted less than a quarter turn — past that there is no meaningful "top"
     * to find, and a card photographed sideways needs rotating, not reordering.
     *
     * @param  array<int, array{0: float, 1: float}>  $points
     * @return array<int, array{0: float, 1: float}>
     */
    public static function ordered(array $points): array
    {
        if (count($points) !== 4) {
            return $points;
        }

        $points = array_values($points);

        $sums = array_map(fn (array $p) => $p[0] + $p[1], $points);
        $diffs = array_map(fn (array $p) => $p[0] - $p[1], $points);

        $tl = (int) array_search(min($sums), $sums, true);
        $br = (int) array_search(max($sums), $sums, true);
        $tr = (int) array_search(max($diffs), $diffs, true);
        $bl = (int) array_search(min($diffs), $diffs, true);

        // A degenerate quad — three corners in a line, say — can name the same
        // point twice. Reordering that would drop a corner and warp to
        // nonsense, so it is left exactly as it came.
        if (count(array_unique([$tl, $tr, $br, $bl])) !== 4) {
            return $points;
        }

        return [$points[$tl], $points[$tr], $points[$br], $points[$bl]];
    }
}
