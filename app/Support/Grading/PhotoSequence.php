<?php

namespace App\Support\Grading;

use RuntimeException;

/**
 * A set of photos of one card, decoded to luma and checked for the things the
 * surface pipeline requires of them.
 *
 * The warper differences frames against each other, so every frame has to share
 * a source geometry — and the whole method rests on the glare having MOVED
 * between shots, which is why one photo is not a sequence. Both rules are
 * enforced here rather than left to each caller to remember.
 *
 * Extracted from the diagnostic command so the CLI and the web tester decode
 * photos the same way. Two implementations of "turn a JPEG into luma" is two
 * chances for the tester to disagree with the harness the pipeline was
 * validated against.
 */
class PhotoSequence
{
    /**
     * @param  array<int, array<int, float>>  $frames  Rec. 601 luma per frame
     */
    private function __construct(
        public readonly array $frames,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /**
     * @param  array<int, string>  $binaries  raw image bytes, one per photo
     *
     * @throws RuntimeException
     */
    public static function fromBinaries(array $binaries, int $maxWidth = 1400): self
    {
        if (count($binaries) < 2) {
            throw new RuntimeException(
                'Need at least two photos — a single image carries no specular information.',
            );
        }

        $frames = [];
        $w0 = $h0 = null;

        foreach (array_values($binaries) as $i => $bytes) {
            $img = @imagecreatefromstring($bytes);

            if ($img === false) {
                throw new RuntimeException('Photo '.($i + 1).' could not be read as an image.');
            }

            $w = imagesx($img);
            $h = imagesy($img);

            // Downscale first: the pipeline is O(pixels) and a phone photo is
            // twelve megapixels of detail the warp throws away anyway.
            if ($w > $maxWidth) {
                $scaled = imagescale($img, $maxWidth);

                if ($scaled !== false) {
                    imagedestroy($img);
                    $img = $scaled;
                    $w = imagesx($img);
                    $h = imagesy($img);
                }
            }

            $w0 ??= $w;
            $h0 ??= $h;

            if ($w !== $w0 || $h !== $h0) {
                imagedestroy($img);

                throw new RuntimeException(
                    'Every photo must be the same size. Photo '.($i + 1)." is {$w}×{$h}, expected {$w0}×{$h0}.",
                );
            }

            $luma = [];

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgb = imagecolorat($img, $x, $y);
                    // Rec. 601 luma — the standard perceptual weighting.
                    $luma[] = 0.299 * (($rgb >> 16) & 0xFF)
                            + 0.587 * (($rgb >> 8) & 0xFF)
                            + 0.114 * ($rgb & 0xFF);
                }
            }

            imagedestroy($img);
            $frames[] = $luma;
        }

        return new self($frames, (int) $w0, (int) $h0);
    }

    /**
     * A luma map as a PNG data URI, so an intermediate can be looked at.
     *
     * The pipeline's own documentation insists on judging these by eye —
     * "albedo should look like a clean flat card; detail should be near-black
     * except for scratches" — so the maps have to reach a screen, not just a
     * number.
     *
     * @param  array<int, float>  $map
     */
    public static function toDataUri(array $map, int $width, int $height, bool $autoScale = false): string
    {
        $img = imagecreatetruecolor($width, $height);

        $lo = 0.0;
        $hi = 255.0;

        if ($autoScale) {
            // The detail map's range is tiny and arbitrary; stretched, a scratch
            // is visible, unstretched it is indistinguishable from black.
            $lo = min($map);
            $hi = max($map);
            $hi = $hi - $lo < 1e-6 ? $lo + 1 : $hi;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $v = (int) round((($map[$y * $width + $x] ?? 0) - $lo) / ($hi - $lo) * 255);
                $v = max(0, min(255, $v));
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $v, $v, $v));
            }
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
