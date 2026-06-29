<?php

namespace App\Support\Amazon;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetch a single Amazon product via the Oxylabs `amazon_product` source with
 * structured parsing (`parse: true`), returning a normalized snapshot. Shares
 * the Oxylabs credentials with the eBay scraper (config services.oxylabs).
 *
 * @see https://developers.oxylabs.io/api-targets/e-commerce/amazon/product
 */
class AmazonProductClient
{
    /**
     * @return array{title: ?string, price: ?int, currency: ?string, stock: ?string, in_stock: bool, raw: array<string, mixed>}
     */
    public function fetch(string $asin, string $domain = 'com', ?string $geo = 'United States'): array
    {
        $config = config('services.oxylabs');

        if (empty($config['username']) || empty($config['password'])) {
            throw new RuntimeException('Oxylabs credentials are not configured.');
        }

        $payload = [
            'source' => 'amazon_product',
            'query' => $asin,
            'domain' => $domain,
            'parse' => true,
        ];

        if ($geo) {
            $payload['geo_location'] = $geo;
        }

        $response = Http::withBasicAuth($config['username'], $config['password'])
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post($config['endpoint'], $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Oxylabs request failed: HTTP {$response->status()}");
        }

        $content = $response->json('results.0.content');

        if (! is_array($content)) {
            throw new RuntimeException('Oxylabs returned no parsed product content.');
        }

        return $this->normalize($content);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{title: ?string, price: ?int, currency: ?string, stock: ?string, in_stock: bool, raw: array<string, mixed>}
     */
    public function normalize(array $content): array
    {
        $stock = $this->firstString($content, ['stock'])
            ?? $this->buyboxStock($content);

        $price = $this->firstFloat($content, ['price', 'price_buybox', 'price_initial']);

        return [
            'title' => $this->firstString($content, ['title']),
            'price' => $price === null ? null : (int) round($price * 100),
            'currency' => $this->firstString($content, ['currency']),
            'stock' => $stock,
            'in_stock' => $this->isInStock($stock, $price),
            'raw' => $content,
        ];
    }

    /**
     * In stock when the availability text reads positively and a price exists.
     * Amazon phrasings: "In Stock", "Only 3 left in stock - order soon." vs.
     * "Currently unavailable.", "Temporarily out of stock.".
     */
    private function isInStock(?string $stock, ?float $price): bool
    {
        if ($price === null || $price <= 0) {
            return false;
        }

        if ($stock === null || $stock === '') {
            // Some listings omit stock text but still expose a buyable price.
            return true;
        }

        $text = strtolower($stock);

        if (preg_match('/unavailable|out of stock|not in stock|sold out|currently not available/', $text)) {
            return false;
        }

        return (bool) preg_match('/in stock|left in stock|only \d+ left|available|ships|usually/', $text);
    }

    /** @param array<string, mixed> $content */
    private function buyboxStock(array $content): ?string
    {
        $box = $content['buybox'] ?? null;

        if (is_array($box) && isset($box[0]) && is_array($box[0])) {
            return $this->firstString($box[0], ['stock']);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $keys
     */
    private function firstString(array $content, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $content[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<int, string>  $keys
     */
    private function firstFloat(array $content, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $content[$key] ?? null;
            if (is_numeric($value) && (float) $value > 0) {
                return (float) $value;
            }
        }

        return null;
    }
}
