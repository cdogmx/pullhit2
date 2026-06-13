<?php

namespace App\Support\Catalog;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads a card image and stores it in our S3 bucket under an organized
 * `phb/` prefix, returning a servable URL. Idempotent (skips if already stored)
 * and best-effort — returns null on any failure so an import never blocks on
 * image I/O (the caller falls back to the source URL).
 */
class CardImageStore
{
    /**
     * Download an external image and store it in our bucket — so we serve our own
     * copy instead of hot-linking. Returns the stored URL, or null on failure.
     */
    public function storeFromUrl(string $url): ?string
    {
        try {
            $response = Http::timeout(60)->retry(2, 1000, throw: false)->get($url);
            if (! $response->successful()) {
                return null;
            }

            return $this->putUpload($response->body(), $this->ext($response->header('Content-Type'), $url));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Store an admin-uploaded image file in our bucket. */
    public function storeUpload(UploadedFile $file): ?string
    {
        try {
            return $this->putUpload(
                (string) file_get_contents($file->getRealPath()),
                $file->extension() ?: $this->ext($file->getMimeType(), $file->getClientOriginalName()),
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function putUpload(string $bytes, string $ext): ?string
    {
        $key = 'phb/uploads/'.Str::uuid()->toString().'.'.$ext;
        $disk = Storage::disk('s3');
        $disk->put($key, $bytes, 'public');

        return $disk->url($key);
    }

    private function ext(?string $mime, string $fallback): string
    {
        return match (true) {
            str_contains((string) $mime, 'png') => 'png',
            str_contains((string) $mime, 'webp') => 'webp',
            str_contains((string) $mime, 'gif') => 'gif',
            str_contains((string) $mime, 'jpeg'), str_contains((string) $mime, 'jpg') => 'jpg',
            default => pathinfo((string) parse_url($fallback, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg',
        };
    }

    public function store(string $setId, string $ptcgioId, ?string $sourceUrl): ?string
    {
        if (! $sourceUrl) {
            return null;
        }

        $key = "phb/pokemon/{$setId}/{$ptcgioId}.png";

        try {
            $disk = Storage::disk('s3');

            if ($disk->exists($key)) {
                return $disk->url($key);
            }

            $response = Http::timeout(60)->retry(2, 1000, throw: false)->get($sourceUrl);
            if (! $response->successful()) {
                return null;
            }

            $disk->put($key, $response->body(), 'public');

            return $disk->url($key);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
