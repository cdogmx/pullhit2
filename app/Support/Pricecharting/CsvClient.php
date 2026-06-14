<?php

namespace App\Support\Pricecharting;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Downloads the PriceCharting Legendary price-guide CSV for a category. The token
 * (services.pricecharting.token ← PRICECHARTING_API_KEY) authorizes the download
 * and is never logged.
 */
class CsvClient
{
    public function fetch(string $category = 'pokemon-cards'): string
    {
        $config = config('services.pricecharting');

        if (empty($config['token'])) {
            throw new RuntimeException('PRICECHARTING_API_KEY is not configured.');
        }

        $response = Http::timeout(180)
            ->retry(2, 3000, throw: false)
            ->get(rtrim($config['base_url'], '/').'/price-guide/download-custom', [
                't' => $config['token'],
                'category' => $category,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("PriceCharting CSV download failed: HTTP {$response->status()}");
        }

        return $response->body();
    }
}
