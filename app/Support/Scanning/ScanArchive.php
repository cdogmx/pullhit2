<?php

namespace App\Support\Scanning;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Persists a scan for the user's history: a downscaled thumbnail of the scanned
 * photo in our bucket, and a lightweight snapshot of the detections (AI read +
 * top catalog match per card). Best-effort — never breaks a scan.
 */
class ScanArchive
{
    private const THUMB_PX = 640;

    /**
     * @param  array<int, array<string, mixed>>  $detected
     * @return array{image_path: ?string, results: array<int, array<string, mixed>>}
     */
    public function build(User $user, string $base64, array $detected): array
    {
        return [
            'image_path' => $this->storeThumbnail($user, $base64),
            'results' => array_map($this->summarize(...), $detected),
        ];
    }

    private function storeThumbnail(User $user, string $base64): ?string
    {
        try {
            $image = (new ImageManager(new Driver))->decodeBinary(base64_decode($base64));
            $image->scaleDown(self::THUMB_PX, self::THUMB_PX);
            $bytes = (string) $image->encode(new JpegEncoder(quality: 75));

            $key = "phb/scans/{$user->id}/".Str::uuid()->toString().'.jpg';
            $disk = Storage::disk('s3');
            $disk->put($key, $bytes, 'public');

            return $disk->url($key);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function summarize(array $card): array
    {
        $top = $card['candidates'][0]['card'] ?? null;

        return [
            'name' => $card['identified']['name'] ?? null,
            'number' => $card['identified']['number'] ?? null,
            'source' => $card['source'] ?? 'vision',
            'match' => $top ? [
                'id' => $top['id'] ?? null,
                'name' => $top['display_name'] ?? ($top['name'] ?? null),
                'number' => $top['number'] ?? null,
                'set' => $top['set']['name'] ?? null,
                'image_url' => $top['image_url'] ?? null,
                'url' => $top['url'] ?? null,
            ] : null,
        ];
    }
}
