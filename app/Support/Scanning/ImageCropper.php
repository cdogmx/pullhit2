<?php

namespace App\Support\Scanning;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

/**
 * Crops card regions out of a binder/page photo using normalized boxes (0–1)
 * from the detection pass, so each card is re-identified at full resolution.
 */
class ImageCropper
{
    /**
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return string cropped JPEG binary
     */
    public function crop(string $binary, array $box): string
    {
        $image = (new ImageManager(new Driver))->decodeBinary($binary);
        $w = $image->width();
        $h = $image->height();

        $cropW = max(1, (int) round($box['width'] * $w));
        $cropH = max(1, (int) round($box['height'] * $h));
        $offsetX = max(0, min($w - 1, (int) round($box['x'] * $w)));
        $offsetY = max(0, min($h - 1, (int) round($box['y'] * $h)));

        // Keep the crop window inside the image bounds.
        $cropW = min($cropW, $w - $offsetX);
        $cropH = min($cropH, $h - $offsetY);

        $image->crop($cropW, $cropH, $offsetX, $offsetY);

        return (string) $image->encode(new JpegEncoder(quality: 85));
    }
}
