<?php

namespace App\Support\Grading;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Photo bytes with the camera's rotation actually applied to the pixels.
 *
 * A phone does not rotate what it records. It writes the sensor's buffer and
 * an EXIF tag saying which way up the result should be shown, and a browser
 * honours that tag. GD does not: imagecreatefromstring hands back the raw
 * buffer, so a portrait photo arrives on the server as a landscape one.
 *
 * Everything in this pipeline then disagrees about where anything is. A corner
 * dragged onto the top left of the card in a browser is somewhere along the
 * side of it in the buffer, the warp takes a quad that is a quarter turn from
 * where it was drawn, and the card comes out rotated and skewed — which is
 * exactly what it did.
 *
 * So the tag is applied to the pixels once, at the edge, before anything
 * measures them. After this the server's idea of up and the browser's are the
 * same, which is the only arrangement in which normalised coordinates mean
 * anything at all.
 */
class UprightImage
{
    /**
     * @param  string  $binary  raw image bytes, possibly EXIF-rotated
     * @return string bytes whose pixels are the right way up
     */
    public static function bytes(string $binary): string
    {
        try {
            $image = (new ImageManager(new Driver))->decodeBinary($binary)->orient();

            // PNG: this is an intermediate for a measurement, and re-encoding
            // as JPEG would add ringing to the edges the surface read is about
            // to hunt for scratches along.
            return (string) $image->encode(new PngEncoder);
        } catch (Throwable $e) {
            report($e);

            // An image we cannot re-encode is one GD may still read. Better a
            // possibly-rotated reading than no reading at all — and a rotation
            // is visible on screen, where this fails loudly rather than
            // silently.
            return $binary;
        }
    }
}
