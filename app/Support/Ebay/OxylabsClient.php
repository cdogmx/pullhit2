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
    /**
     * Fetch a URL's HTML via Oxylabs. `render` defaults to true (headless render,
     * needed for JS-built pages like eBay). Pass false for sites that serve their
     * full content server-side AND fingerprint headless browsers — PriceCharting
     * strips its sold-listings tables when it detects a rendered/headless client,
     * so we fetch those plain.
     */
    public function fetchHtml(string $url, string $geo = 'United States', bool $render = true): string
    {
        $config = config('services.oxylabs');

        if (empty($config['username']) || empty($config['password'])) {
            throw new RuntimeException('Oxylabs credentials are not configured.');
        }

        $payload = [
            'source' => 'universal',
            'url' => $url,
            'geo_location' => $geo,
        ];

        if ($render) {
            $payload['render'] = 'html';
        }

        $response = Http::withBasicAuth($config['username'], $config['password'])
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post($config['endpoint'], $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Oxylabs request failed: HTTP {$response->status()}");
        }

        return (string) ($response->json('results.0.content') ?? '');
    }
}
