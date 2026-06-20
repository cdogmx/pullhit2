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
     * @param  float  $pad  Margin to add on each side, as a fraction of the box's
     *                      own size (0.08 = 8%). Stops tight detection boxes from
     *                      clipping card borders. Clamped to the image bounds.
     * @return string cropped JPEG binary
     */
    public function crop(string $binary, array $box, float $pad = 0.0): string
    {
        $image = (new ImageManager(new Driver))->decodeBinary($binary);
        $w = $image->width();
        $h = $image->height();

        $box = $this->pad($box, $pad);

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

    /**
     * Expand a normalized box by $pad of its own width/height on every side,
     * clamped to [0, 1] so it never runs off the image.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{x: float, y: float, width: float, height: float}
     */
    private function pad(array $box, float $pad): array
    {
        if ($pad <= 0) {
            return $box;
        }

        $padX = $box['width'] * $pad;
        $padY = $box['height'] * $pad;

        $x = max(0.0, $box['x'] - $padX);
        $y = max(0.0, $box['y'] - $padY);

        return [
            'x' => $x,
            'y' => $y,
            'width' => min(1.0 - $x, $box['width'] + 2 * $padX),
            'height' => min(1.0 - $y, $box['height'] + 2 * $padY),
        ];
    }
}
