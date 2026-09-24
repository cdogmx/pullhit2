<?php

namespace App\Support\Grading;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Keeps the pictures a saved prediction was read from.
 *
 * Runs originally threw their images away — megabytes apiece, and the reading
 * was the thing worth keeping. That was right for a record and wrong for a
 * bench: when a number looks off, the first question is always whether the
 * guide was on the border, and no amount of stored arithmetic answers it. A
 * straightened card at 700px with the guide drawn over it does, and costs
 * about eighty kilobytes.
 *
 * Only two per side are kept. The straightened card, because the guides were
 * placed on it and a guide is only meaningful against the picture it was
 * placed on. And the detail map, because "is that a scratch or did the frames
 * fail to align" is the other question a number cannot settle.
 *
 * Best-effort throughout: a prediction that saves without its pictures is
 * worse, not broken.
 */
class PredictionArchive
{
    private const MAX_PX = 700;

    /**
     * @param  array<string, array<string, mixed>>  $sides  side => the reading,
     *                                                      with data-URI images
     * @return array<string, array<string, mixed>>
     */
    public function store(int $userId, array $sides): array
    {
        foreach ($sides as $name => $side) {
            $images = $side['images'] ?? [];

            $sides[$name]['stored_images'] = array_filter([
                'card' => $this->put($userId, $images['card'] ?? null),
                'detail' => $this->put($userId, $images['detail'] ?? null),
            ]);

            // The data URIs themselves never reach the database: they are the
            // megabytes the row was avoiding in the first place.
            unset($sides[$name]['images']);
        }

        return $sides;
    }

    private function put(int $userId, ?string $dataUri): ?string
    {
        if (! is_string($dataUri) || ! str_starts_with($dataUri, 'data:image/')) {
            return null;
        }

        try {
            $binary = base64_decode((string) Str::after($dataUri, 'base64,'), true);

            if ($binary === false) {
                return null;
            }

            $image = (new ImageManager(new Driver))->decodeBinary($binary);
            $image->scaleDown(self::MAX_PX, self::MAX_PX);

            // JPEG at 80: this is evidence for a person's eye, not input to the
            // surface read, which has already run on the full-resolution frames.
            $bytes = (string) $image->encode(new JpegEncoder(quality: 80));

            $key = "phb/grading/{$userId}/".Str::uuid()->toString().'.jpg';
            $disk = Storage::disk('s3');
            $disk->put($key, $bytes, 'public');

            return $disk->url($key);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
