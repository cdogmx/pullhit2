<?php

namespace App\Support\Grading;

use RuntimeException;

/**
 * Warps a quadrilateral out of a photo into a straight rectangle, in colour.
 *
 * FrameWarper already does this for the surface pipeline, but it works in luma
 * because that is all the surface read needs. A person placing a guide on the
 * inner edge of a yellow border needs to see the yellow, so this one keeps the
 * channels.
 *
 * The output aspect is the card's, not the quad's. A card photographed at an
 * angle has a near edge longer than its far one, and fitting the output to
 * either would keep some of the very distortion this removes.
 */
class ImageRectifier
{
    /** A trading card, 2.5" × 3.5". Every card this tool grades is this shape. */
    public const CARD_ASPECT = 2.5 / 3.5;

    /**
     * @param  array<int, array{x: float, y: float}>  $quad  corners as fractions
     *                                                       of the image,
     *                                                       clockwise from top left
     */
    public function rectify(string $binary, array $quad, int $outWidth = 700): string
    {
        // Same reason as PhotoSequence: the quad was drawn on the picture a
        // browser showed, which is the EXIF-corrected one.
        $src = UprightImage::decode($binary);

        if ($src === false) {
            throw new RuntimeException('Could not read that as an image.');
        }

        if (count($quad) !== 4) {
            imagedestroy($src);

            throw new RuntimeException('A card outline needs exactly four corners.');
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // Ordered, not trusted: the homography maps positionally, so corners
        // arriving from a different starting point warp the card a quarter
        // turn round — a picture that is wrong in a way no number notices.
        $corners = Quad::ordered(array_map(
            fn (array $p) => [(float) $p['x'] * $srcW, (float) $p['y'] * $srcH],
            array_values($quad),
        ));

        $outWidth = max(100, min(1600, $outWidth));
        $outHeight = (int) round($outWidth / self::CARD_ASPECT);

        $out = imagecreatetruecolor($outWidth, $outHeight);

        // Output pixel -> source pixel, so every output pixel gets a value.
        // Mapping the other way leaves holes wherever the source stretches.
        $toSource = Homography::between(
            [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]],
            $corners,
        );

        for ($y = 0; $y < $outHeight; $y++) {
            for ($x = 0; $x < $outWidth; $x++) {
                [$sx, $sy] = $toSource->apply(
                    ($x + 0.5) / $outWidth,
                    ($y + 0.5) / $outHeight,
                );

                $rgb = $this->sample($src, $srcW, $srcH, $sx, $sy);
                imagesetpixel($out, $x, $y, $rgb);
            }
        }

        imagedestroy($src);

        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();
        imagedestroy($out);

        return $png;
    }

    /**
     * Bilinear sample, because nearest-neighbour would alias the card's own
     * print texture into something the surface read might treat as damage.
     *
     * @param  \GdImage  $img
     */
    private function sample($img, int $w, int $h, float $x, float $y): int
    {
        $x = max(0.0, min($w - 1.001, $x));
        $y = max(0.0, min($h - 1.001, $y));

        $x0 = (int) $x;
        $y0 = (int) $y;
        $fx = $x - $x0;
        $fy = $y - $y0;

        $c00 = imagecolorat($img, $x0, $y0);
        $c10 = imagecolorat($img, $x0 + 1, $y0);
        $c01 = imagecolorat($img, $x0, $y0 + 1);
        $c11 = imagecolorat($img, $x0 + 1, $y0 + 1);

        $mix = function (int $shift) use ($c00, $c10, $c01, $c11, $fx, $fy) {
            $a = ($c00 >> $shift) & 0xFF;
            $b = ($c10 >> $shift) & 0xFF;
            $c = ($c01 >> $shift) & 0xFF;
            $d = ($c11 >> $shift) & 0xFF;

            $top = $a + ($b - $a) * $fx;
            $bottom = $c + ($d - $c) * $fx;

            return (int) round($top + ($bottom - $top) * $fy);
        };

        return ($mix(16) << 16) | ($mix(8) << 8) | $mix(0);
    }
}
