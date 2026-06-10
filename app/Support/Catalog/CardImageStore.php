<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Downloads a card image and stores it in our S3 bucket under an organized
 * `phb/` prefix, returning a servable URL. Idempotent (skips if already stored)
 * and best-effort — returns null on any failure so an import never blocks on
 * image I/O (the caller falls back to the source URL).
 */
class CardImageStore
{
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
