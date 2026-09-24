<?php

namespace App\Support\Grading;

use RuntimeException;

/**
 * A set of photos of one card, decoded to luma and checked for the things the
 * surface pipeline requires of them.
 *
 * The warper differences frames against each other, so every frame has to share
 * a source geometry. Frames that disagree are scaled to match rather than
 * refused: phones crop differently between shots, and the homography fitted
 * afterwards absorbs the change.
 *
 * How MANY frames there are is not this class's business. One photo is a valid
 * thing to hold — it simply cannot carry surface information, which is a fact
 * about the surface read, not about the photo.
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
    /**
     * @param  array<int, string>  $binaries  raw image bytes, one per photo
     *
     * @throws RuntimeException
     */
    public static function fromBinaries(array $binaries, int $maxWidth = 1400): self
    {
        if ($binaries === []) {
            throw new RuntimeException('No photos given.');
        }

        $images = [];

        foreach (array_values($binaries) as $i => $bytes) {
            // EXIF first: a phone writes the sensor buffer plus a tag, and GD
            // ignores the tag. Without this the server measures a photo a
            // quarter turn from the one the guides were drawn on.
            $img = @imagecreatefromstring(UprightImage::bytes($bytes));

            if ($img === false) {
                throw new RuntimeException('Photo '.($i + 1).' could not be read as an image.');
            }

            // Downscale first: the pipeline is O(pixels) and a phone photo is
            // twelve megapixels of detail the warp throws away anyway.
            if (imagesx($img) > $maxWidth) {
                $scaled = imagescale($img, $maxWidth);

                if ($scaled !== false) {
                    imagedestroy($img);
                    $img = $scaled;
                }
            }

            $images[] = $img;
        }

        // Match the frames to a common geometry rather than refusing them.
        //
        // The warper differences frames pixel-for-pixel, so they must agree on
        // size — but phones crop differently between shots and a person
        // shooting a tilt sequence should not have to fight that. Scaling is
        // safe here because the homography that follows is fitted to corners
        // detected AFTER this, so it absorbs the change.
        $width = min(array_map('imagesx', $images));
        $height = min(array_map('imagesy', $images));

        $frames = [];

        foreach ($images as $img) {
            if (imagesx($img) !== $width || imagesy($img) !== $height) {
                $fitted = imagecreatetruecolor($width, $height);
                imagecopyresampled(
                    $fitted, $img,
                    0, 0, 0, 0,
                    $width, $height, imagesx($img), imagesy($img),
                );
                imagedestroy($img);
                $img = $fitted;
            }

            $luma = [];

            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
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

        return new self($frames, $width, $height);
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
