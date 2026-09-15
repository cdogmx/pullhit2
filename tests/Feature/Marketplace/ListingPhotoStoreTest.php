<?php

use App\Support\Marketplace\ListingPhotoStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    $this->store = app(ListingPhotoStore::class);
});

/**
 * A JPEG carrying a GPS EXIF block, written by hand — a phone photo of a card
 * has one of these, and publishing it unchanged publishes the seller's address.
 */
function jpegWithGps(): string
{
    $image = imagecreatetruecolor(40, 56);
    imagefilledrectangle($image, 0, 0, 40, 56, imagecolorallocate($image, 200, 30, 30));

    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);

    // An APP1 segment announcing itself as Exif, spliced in after SOI. Real EXIF
    // is a TIFF tree; this is enough to prove a re-encode drops the segment,
    // which is what actually removes the GPS tags.
    $exif = "Exif\0\0".str_repeat("\x00", 64).'GPSLatitude';
    $segment = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    return substr($jpeg, 0, 2).$segment.substr($jpeg, 2);
}

test('the stored photo carries no EXIF from the original', function () {
    $path = sys_get_temp_dir().'/listing-exif-'.uniqid().'.jpg';
    file_put_contents($path, jpegWithGps());

    expect(file_get_contents($path))->toContain('GPSLatitude');

    $url = $this->store->store(new UploadedFile($path, 'card.jpg', 'image/jpeg', null, true));

    expect($url)->not->toBeNull();

    $key = 'marketplace/listings/'.basename(parse_url($url, PHP_URL_PATH));
    $stored = Storage::disk('s3')->get($key);

    // Re-encoding is what does it: the decoder reads pixels, the encoder writes
    // a new file, and no EXIF block survives the trip.
    expect($stored)->not->toContain('GPSLatitude')
        ->and($stored)->not->toContain('Exif')
        // Still a real image, not an empty file.
        ->and(strlen($stored))->toBeGreaterThan(500);

    @unlink($path);
});

test('an oversized photo is scaled down, a small one is left alone', function () {
    $big = UploadedFile::fake()->image('big.jpg', 3000, 2000);
    $url = $this->store->store($big);

    $key = 'marketplace/listings/'.basename(parse_url($url, PHP_URL_PATH));
    [$width, $height] = getimagesizefromstring(Storage::disk('s3')->get($key));

    expect($width)->toBe(1600)
        ->and($height)->toBe(1067);

    // scaleDown never enlarges: blowing a thumbnail up to 1600px would only
    // make it blurry and bigger to send.
    $small = UploadedFile::fake()->image('small.jpg', 300, 420);
    $smallUrl = $this->store->store($small);
    $smallKey = 'marketplace/listings/'.basename(parse_url($smallUrl, PHP_URL_PATH));
    [$sw, $sh] = getimagesizefromstring(Storage::disk('s3')->get($smallKey));

    expect($sw)->toBe(300)->and($sh)->toBe(420);
});

test('a file that is not an image is refused rather than stored', function () {
    $path = sys_get_temp_dir().'/not-an-image-'.uniqid().'.jpg';
    file_put_contents($path, 'this is not a jpeg');

    expect($this->store->store(new UploadedFile($path, 'x.jpg', 'image/jpeg', null, true)))->toBeNull();

    @unlink($path);
});

test('every stored photo gets its own key', function () {
    $a = $this->store->store(UploadedFile::fake()->image('same-name.jpg'));
    $b = $this->store->store(UploadedFile::fake()->image('same-name.jpg'));

    expect($a)->not->toBe($b);
});
