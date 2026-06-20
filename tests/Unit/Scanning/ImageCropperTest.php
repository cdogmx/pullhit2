<?php

use App\Support\Scanning\ImageCropper;

function solidJpeg(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocate($im, 51, 102, 204));
    ob_start();
    imagejpeg($im);
    $data = (string) ob_get_clean();
    imagedestroy($im);

    return $data;
}

function dims(string $binary): array
{
    $info = getimagesizefromstring($binary);

    return [$info[0], $info[1]];
}

test('padding enlarges the crop around the box', function () {
    $src = solidJpeg(200, 200);
    $box = ['x' => 0.4, 'y' => 0.4, 'width' => 0.2, 'height' => 0.2]; // 40x40 centre

    expect(dims(app(ImageCropper::class)->crop($src, $box)))->toBe([40, 40])
        // +50% of the box on each side → 0.2 + 0.2 = 0.4 → 80px.
        ->and(dims(app(ImageCropper::class)->crop($src, $box, 0.5)))->toBe([80, 80]);
});

test('a padded box never runs off the image', function () {
    $src = solidJpeg(200, 200);
    // Box hugging the bottom-right corner; heavy padding must clamp inside.
    $box = ['x' => 0.85, 'y' => 0.85, 'width' => 0.15, 'height' => 0.15];

    [$w, $h] = dims(app(ImageCropper::class)->crop($src, $box, 0.8));

    expect($w)->toBeLessThanOrEqual(200)->and($h)->toBeLessThanOrEqual(200)
        ->and($w)->toBeGreaterThan(30)->and($h)->toBeGreaterThan(30);
});
