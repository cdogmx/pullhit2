<?php

namespace App\Enums;

/**
 * Retailers we track for stock alerts. Most have a dedicated Oxylabs source with
 * structured parsing; Sam's Club and Pokémon Center have none, so they're scraped
 * via the universal source + schema.org JSON-LD. Per-retailer request/parse
 * details live here so App\Support\Retail\RetailScraper stays data-driven.
 */
enum Retailer: string
{
    case Amazon = 'amazon';
    case Walmart = 'walmart';
    case Target = 'target';
    case BestBuy = 'bestbuy';
    case Costco = 'costco';
    case SamsClub = 'sams_club';
    case PokemonCenter = 'pokemon_center';

    public function label(): string
    {
        return match ($this) {
            self::Amazon => 'Amazon',
            self::Walmart => 'Walmart',
            self::Target => 'Target',
            self::BestBuy => 'Best Buy',
            self::Costco => 'Costco',
            self::SamsClub => "Sam's Club",
            self::PokemonCenter => 'Pokémon Center',
        };
    }

    /** Oxylabs dedicated source, or null when we fall back to universal + JSON-LD. */
    public function oxylabsSource(): ?string
    {
        return match ($this) {
            self::Amazon => 'amazon_product',
            self::Walmart => 'walmart_product',
            self::Target => 'target_product',
            self::BestBuy => 'bestbuy_product',
            self::Costco => 'costco_product',
            self::SamsClub, self::PokemonCenter => null,
        };
    }

    /** The request param that carries the identifier for the dedicated source. */
    public function idParam(): ?string
    {
        return match ($this) {
            self::Amazon => 'query',          // ASIN
            self::Walmart,
            self::Target,
            self::BestBuy,
            self::Costco => 'product_id',
            self::SamsClub, self::PokemonCenter => null,
        };
    }

    /** Sources that need JS rendering to return complete data. */
    public function needsRender(): bool
    {
        return match ($this) {
            self::Target, self::Costco => true,
            default => false,
        };
    }

    /**
     * Best-effort extraction of the retailer's product identifier from a product
     * URL, so admins can paste a link and we derive the id. Null → the admin must
     * supply it (or, for universal retailers, we just use the URL).
     */
    public function externalIdFromUrl(string $url): ?string
    {
        $patterns = match ($this) {
            self::Amazon => ['#/(?:dp|gp/product|gp/aw/d)/([A-Z0-9]{10})#i'],
            self::Walmart => ['#/ip/(?:[^/?]+/)?(\d{6,})#'],
            self::Target => ['#/A-(\d{4,})#'],
            // New format: /product/<slug>/<ID>; legacy: /site/<slug>/<sku>.p
            self::BestBuy => ['#/product/[^/?]+/([A-Za-z0-9]{6,})#', '#/(\d{6,})\.p#', '#skuId=(\d{6,})#'],
            self::Costco => ['#\.product\.(\d{6,})\.html#'],
            default => [],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $m)) {
                return $this === self::Amazon ? strtoupper($m[1]) : $m[1];
            }
        }

        return null;
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $r) => ['value' => $r->value, 'label' => $r->label()],
            self::cases(),
        );
    }
}
