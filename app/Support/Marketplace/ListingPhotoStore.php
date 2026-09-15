<?php

namespace App\Support\Marketplace;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Store a seller's photo in our bucket, stripped of everything but the picture.
 *
 * Separate from CardImageStore on purpose. That one moves images we fetched from
 * a publisher or a price feed, where the bytes are already public and there is
 * nothing to leak. These come off a person's phone, and a phone writes GPS
 * coordinates into every photo it takes — publishing one unchanged publishes
 * the seller's home address next to a card worth four figures.
 *
 * Re-encoding through GD is what removes it: the decoder reads pixels and the
 * encoder writes a new file, so no EXIF block survives. Checking for a GPS tag
 * and keeping the original otherwise would be cheaper and wrong — it leaves the
 * camera body, the timestamp and the serial number in place, which is enough to
 * tie a burner account to a real one.
 */
class ListingPhotoStore
{
    /** Long edge, in pixels. Bigger than any listing tile needs, small enough to send. */
    private const MAX_EDGE = 1600;

    private const QUALITY = 82;

    public function store(UploadedFile $file): ?string
    {
        try {
            $image = (new ImageManager(new Driver))->decodePath($file->getRealPath());

            // scaleDown never enlarges, so a small photo keeps its own size
            // rather than being blown up into a blurry one.
            $image->scaleDown(width: self::MAX_EDGE, height: self::MAX_EDGE);

            $key = 'marketplace/listings/'.Str::uuid()->toString().'.jpg';
            $disk = Storage::disk('s3');
            $disk->put($key, (string) $image->encode(new JpegEncoder(quality: self::QUALITY)), 'public');

            return $disk->url($key);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
