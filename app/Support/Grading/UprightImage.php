<?php

namespace App\Support\Grading;

use GdImage;

/**
 * A photo decoded with the camera's rotation actually applied to the pixels.
 *
 * A phone does not rotate what it records. It writes the sensor's buffer and
 * an EXIF tag saying which way up the result should be shown, and a browser
 * honours that tag. GD does not: imagecreatefromstring hands back the raw
 * buffer, so a portrait photo arrives on the server as a landscape one, and a
 * corner dragged onto the card's top left in a browser sits along its side
 * here. The warp is then fitted to a quad a quarter turn from where it was
 * drawn, and the card comes out rotated and skewed.
 *
 * The rotation is applied to the decoded image rather than by re-encoding the
 * file. The first version of this did re-encode — to PNG, to avoid adding JPEG
 * ringing to edges the surface read is about to hunt scratches along — and on
 * a real photograph that cost 3.7 seconds and produced a 35 MB string, per
 * frame. Four frames a side was a minute of work and a third of a gigabyte of
 * strings, which is not a slow feature but a hung one. Rotating the GD image
 * costs neither, and is lossless besides.
 */
class UprightImage
{
    /**
     * Decode, the right way up. False when the bytes are not an image at all.
     *
     * @return GdImage|false
     */
    public static function decode(string $binary)
    {
        $img = @imagecreatefromstring($binary);

        if ($img === false) {
            return false;
        }

        $orientation = self::orientation($binary);

        if ($orientation === null || $orientation === 1) {
            return $img;
        }

        // imagerotate turns anticlockwise; EXIF describes the turn needed to
        // display, so the signs are opposite.
        $turn = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($turn !== 0) {
            $rotated = imagerotate($img, $turn, 0);

            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }

        // The even orientations are mirrored as well as turned. Rare from a
        // phone, but a photo flipped by an editor carries one, and a mirrored
        // card would measure its centering the wrong way round.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }

        return $img;
    }

    /** The EXIF orientation tag, or null when there isn't one to read. */
    private static function orientation(string $binary): ?int
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            return null;
        }

        fwrite($stream, $binary);
        rewind($stream);

        // Warnings suppressed rather than handled: a PNG, or a JPEG with no
        // EXIF block, is not a problem — it is simply already upright.
        $exif = @exif_read_data($stream);
        fclose($stream);

        $value = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        return is_numeric($value) ? (int) $value : null;
    }
}
