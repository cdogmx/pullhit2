<?php

namespace App\Support\Retail;

use App\Enums\Retailer;
use App\Models\RetailerLink;
use App\Support\Amazon\AmazonProductClient;
use App\Support\Ebay\OxylabsClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Polls a single retailer link and returns a normalized snapshot:
 *   { title, price (cents|null), currency, stock (text|null), image, in_stock }.
 *
 * Amazon reuses AmazonProductClient. Walmart/Target/Best Buy/Costco use their
 * dedicated Oxylabs sources (each with its own parsed field shape). Sam's Club
 * and Pokémon Center have no dedicated source, so we render the page via the
 * universal source and read schema.org JSON-LD.
 */
class RetailScraper
{
    public function __construct(
        private readonly AmazonProductClient $amazon,
        private readonly OxylabsClient $oxylabs,
    ) {}

    /**
     * @return array{title: ?string, price: ?int, currency: ?string, stock: ?string, image: ?string, in_stock: bool}
     */
    public function fetch(RetailerLink $link): array
    {
        $retailer = $link->retailer;

        if ($retailer === Retailer::Amazon) {
            $asin = $link->external_id ?: $retailer->externalIdFromUrl($link->url);
            if (! $asin) {
                throw new RuntimeException('Could not determine the Amazon ASIN for this link.');
            }

            $snap = $this->amazon->fetch($asin);

            return [
                'title' => $snap['title'], 'price' => $snap['price'], 'currency' => $snap['currency'],
                'stock' => $snap['stock'], 'image' => $snap['image'], 'in_stock' => $snap['in_stock'],
            ];
        }

        if ($retailer->oxylabsSource() !== null) {
            return $this->structured($retailer, $link);
        }

        return $this->universal($link->url);
    }

    /** Dedicated Oxylabs source with structured parsing. */
    private function structured(Retailer $retailer, RetailerLink $link): array
    {
        $id = $link->external_id ?: $retailer->externalIdFromUrl($link->url);
        if (! $id) {
            throw new RuntimeException("Could not determine the {$retailer->label()} product id for this link.");
        }

        $payload = [
            'source' => $retailer->oxylabsSource(),
            $retailer->idParam() => $id,
            'parse' => true,
        ];

        if ($retailer->needsRender()) {
            $payload['render'] = 'html';
        }

        $content = $this->postOxylabs($payload);

        return $this->normalizeStructured($retailer, $content);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{title: ?string, price: ?int, currency: ?string, stock: ?string, image: ?string, in_stock: bool}
     */
    private function normalizeStructured(Retailer $retailer, array $content): array
    {
        // Each dedicated parser exposes a different field shape.
        [$title, $price, $currency, $image, $soldOut, $stockText] = match ($retailer) {
            Retailer::Walmart => [
                data_get($content, 'general.title'),
                data_get($content, 'price.price'),
                data_get($content, 'price.currency'),
                data_get($content, 'general.main_image'),
                (bool) data_get($content, 'fulfillment.out_of_stock'),
                null,
            ],
            Retailer::BestBuy => [
                data_get($content, 'title'),
                data_get($content, 'price.price'),
                data_get($content, 'price.currency'),
                data_get($content, 'images.0') ?? data_get($content, 'main_image'),
                (bool) data_get($content, 'is_sold_out'),
                null,
            ],
            Retailer::Target => [
                data_get($content, 'title'),
                data_get($content, 'price'),
                data_get($content, 'currency'),
                data_get($content, 'images.0') ?? data_get($content, 'main_image'),
                null, // no availability field — inferred from price below
                null,
            ],
            // Costco's parser shape is undocumented; probe the likely paths.
            default => [
                data_get($content, 'title') ?? data_get($content, 'general.title'),
                data_get($content, 'price.price') ?? data_get($content, 'price'),
                data_get($content, 'price.currency') ?? data_get($content, 'currency') ?? 'USD',
                data_get($content, 'main_image') ?? data_get($content, 'general.main_image') ?? data_get($content, 'images.0'),
                null,
                data_get($content, 'stock') ?? data_get($content, 'availability'),
            ],
        };

        $cents = $this->toCents($price);
        // Sold-out flag wins; otherwise a present price means buyable.
        $inStock = $soldOut === true ? false : ($cents !== null && $cents > 0);

        return [
            'title' => is_string($title) ? $title : null,
            'price' => $cents,
            'currency' => is_string($currency) ? $currency : 'USD',
            'stock' => $stockText ?: ($soldOut === true ? 'Out of stock' : ($inStock ? 'In stock' : null)),
            'image' => is_string($image) && str_starts_with($image, 'http') ? $image : null,
            'in_stock' => $inStock,
        ];
    }

    /** Universal render + schema.org JSON-LD (retailers without a dedicated source). */
    private function universal(string $url): array
    {
        $html = $this->oxylabs->fetchHtml($url, budget: OxylabsClient::BUDGET_RETAIL);
        $product = $this->jsonLdProduct($html);

        if ($product === null) {
            throw new RuntimeException('No schema.org product data found on the page.');
        }

        $offer = $product['offers'] ?? null;
        if (is_array($offer) && array_is_list($offer)) {
            $offer = $offer[0] ?? null;
        }

        $price = is_array($offer) ? ($offer['price'] ?? $offer['lowPrice'] ?? null) : null;
        $availability = is_array($offer) ? (string) ($offer['availability'] ?? '') : '';
        $currency = is_array($offer) ? ($offer['priceCurrency'] ?? 'USD') : 'USD';

        $image = $product['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        $cents = $this->toCents($price);

        return [
            'title' => isset($product['name']) ? (string) $product['name'] : null,
            'price' => $cents,
            'currency' => is_string($currency) ? $currency : 'USD',
            'stock' => $availability ?: null,
            'image' => is_string($image) && str_starts_with($image, 'http') ? $image : null,
            'in_stock' => $this->availabilityInStock($availability, $cents),
        ];
    }

    /** Find the first schema.org Product node (handles @graph and arrays). */
    private function jsonLdProduct(string $html): ?array
    {
        if (! preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $block) {
            $data = json_decode(trim($block), true);
            if (! is_array($data)) {
                continue;
            }

            foreach ($this->flattenJsonLd($data) as $node) {
                $type = $node['@type'] ?? null;
                $type = is_array($type) ? $type : [$type];
                if (array_intersect(['Product', 'ProductGroup'], $type) && isset($node['offers'])) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function flattenJsonLd(array $data): array
    {
        $nodes = [];

        if (isset($data['@graph']) && is_array($data['@graph'])) {
            foreach ($data['@graph'] as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }

            return $nodes;
        }

        if (array_is_list($data)) {
            foreach ($data as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }

            return $nodes;
        }

        return [$data];
    }

    private function availabilityInStock(string $availability, ?int $cents): bool
    {
        if ($cents === null || $cents <= 0) {
            return false;
        }

        $a = strtolower($availability);

        if (str_contains($a, 'outofstock') || str_contains($a, 'soldout') || str_contains($a, 'discontinued')) {
            return false;
        }

        // No availability hint but a price is present → treat as buyable.
        return $availability === ''
            || str_contains($a, 'instock')
            || str_contains($a, 'limitedavailability')
            || str_contains($a, 'onlineonly')
            || str_contains($a, 'instoreonly')
            || str_contains($a, 'preorder');
    }

    private function toCents(mixed $price): ?int
    {
        if (is_string($price)) {
            $price = (float) preg_replace('/[^0-9.]/', '', $price);
        }

        return is_numeric($price) && (float) $price > 0 ? (int) round((float) $price * 100) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function postOxylabs(array $payload): array
    {
        $config = config('services.oxylabs');

        if (empty($config['username']) || empty($config['password'])) {
            throw new RuntimeException('Oxylabs credentials are not configured.');
        }

        $response = Http::withBasicAuth($config['username'], $config['password'])
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post($config['endpoint'], $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Oxylabs request failed: HTTP {$response->status()} {$response->body()}");
        }

        $content = $response->json('results.0.content');

        if (! is_array($content)) {
            throw new RuntimeException('Oxylabs returned no parsed product content.');
        }

        return $content;
    }
}
