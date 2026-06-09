<?php

namespace App\Support\Ebay;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Oxylabs Web Scraper API (realtime endpoint). Fetches a
 * public URL via the `universal` source and returns the rendered HTML. Credentials
 * come from config('services.oxylabs') and are never logged.
 */
class OxylabsClient
{
    public function fetchHtml(string $url, string $geo = 'United States'): string
    {
        $config = config('services.oxylabs');

        if (empty($config['username']) || empty($config['password'])) {
            throw new RuntimeException('Oxylabs credentials are not configured.');
        }

        $response = Http::withBasicAuth($config['username'], $config['password'])
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post($config['endpoint'], [
                'source' => 'universal',
                'url' => $url,
                'geo_location' => $geo,
                'render' => 'html',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Oxylabs request failed: HTTP {$response->status()}");
        }

        return (string) ($response->json('results.0.content') ?? '');
    }
}
