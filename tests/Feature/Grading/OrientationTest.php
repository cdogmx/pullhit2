<?php

use App\Support\Grading\ImageRectifier;
use App\Support\Grading\Quad;
use App\Support\Grading\UprightImage;

/**
 * A JPEG whose pixels are landscape and whose EXIF says "rotate 90° CW".
 *
 * This is what a phone hands over for a portrait photo: the sensor buffer as
 * recorded, plus a tag saying which way up to show it. A browser honours the
 * tag; GD does not.
 */
function rotatedJpeg(): string
{
    // Landscape buffer: wide red band on the left, so a rotation is visible.
    $img = imagecreatetruecolor(400, 200);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 30, 30));
    imagefilledrectangle($img, 0, 0, 99, 199, imagecolorallocate($img, 220, 40, 40));

    ob_start();
    imagejpeg($img, null, 92);
    $jpeg = (string) ob_get_clean();
    imagedestroy($img);

    // Orientation 6 — "rotate 90° clockwise to display" — spliced in as an
    // APP1/EXIF segment straight after SOI.
    $exif = "Exif\x00\x00MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
    $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

test('a phone photo is turned the right way up before anything measures it', function () {
    // The bug this prevents: the browser shows the photo upright, the server
    // reads the raw buffer sideways, and a corner dragged onto the card's top
    // left lands along its side. The card then warps out rotated and skewed.
    $raw = rotatedJpeg();

    $before = imagecreatefromstring($raw);
    expect(imagesx($before))->toBe(400)
        ->and(imagesy($before))->toBe(200);
    imagedestroy($before);

    $after = imagecreatefromstring(UprightImage::bytes($raw));

    // Turned: the landscape buffer is now the portrait picture a phone meant.
    expect(imagesx($after))->toBe(200)
        ->and(imagesy($after))->toBe(400);

    imagedestroy($after);
});

test('an image with no EXIF is left exactly as it is', function () {
    $img = imagecreatetruecolor(120, 80);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 200, 10));
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    imagedestroy($img);

    $after = imagecreatefromstring(UprightImage::bytes($png));

    expect(imagesx($after))->toBe(120)->and(imagesy($after))->toBe(80);

    imagedestroy($after);
});

test('bytes that are not an image come back untouched rather than throwing', function () {
    // Better a reading GD might still manage than no reading at all.
    expect(UprightImage::bytes('not an image'))->toBe('not an image');
});

test('corners are put in order however they arrive', function () {
    $tl = [10.0, 20.0];
    $tr = [90.0, 22.0];
    $br = [88.0, 140.0];
    $bl = [12.0, 138.0];

    // Starting from the bottom right, and anticlockwise — both things a drag
    // can produce without anybody noticing.
    expect(Quad::ordered([$br, $bl, $tl, $tr]))->toBe([$tl, $tr, $br, $bl])
        ->and(Quad::ordered([$tl, $bl, $br, $tr]))->toBe([$tl, $tr, $br, $bl])
        ->and(Quad::ordered([$tl, $tr, $br, $bl]))->toBe([$tl, $tr, $br, $bl]);
});

test('a degenerate quad is left alone rather than silently losing a corner', function () {
    // Three points in a line name the same corner twice. Reordering would drop
    // one and warp to nonsense.
    $flat = [[0.0, 0.0], [10.0, 10.0], [20.0, 20.0], [30.0, 30.0]];

    expect(Quad::ordered($flat))->toBe($flat);
});

test('the warp is not fooled by the order the corners came in', function () {
    $img = imagecreatetruecolor(300, 300);
    imagefill($img, 0, 0, imagecolorallocate($img, 20, 20, 20));
    // A field with a distinctly coloured top-left, so a rotation shows.
    imagefilledrectangle($img, 60, 60, 240, 240, imagecolorallocate($img, 40, 40, 220));
    imagefilledrectangle($img, 60, 60, 120, 120, imagecolorallocate($img, 240, 220, 40));
    ob_start();
    imagepng($img);
    $png = (string) ob_get_clean();
    imagedestroy($img);

    $corners = [
        ['x' => 0.2, 'y' => 0.2],
        ['x' => 0.8, 'y' => 0.2],
        ['x' => 0.8, 'y' => 0.8],
        ['x' => 0.2, 'y' => 0.8],
    ];

    $rectifier = new ImageRectifier;

    $straight = imagecreatefromstring($rectifier->rectify($png, $corners, 200));
    // Handed the same corners from a different starting point.
    $rotated = imagecreatefromstring($rectifier->rectify(
        $png,
        [$corners[2], $corners[3], $corners[0], $corners[1]],
        200,
    ));

    // The yellow patch stays top-left in both.
    foreach ([$straight, $rotated] as $out) {
        $rgb = imagecolorat($out, 20, 20);
        expect(($rgb >> 16) & 0xFF)->toBeGreaterThan(180)
            ->and($rgb & 0xFF)->toBeLessThan(120);
        imagedestroy($out);
    }
});
