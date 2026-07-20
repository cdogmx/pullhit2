<?php

namespace App\Support\Valuation;

use App\Models\CatalogItem;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The current lowest TCGplayer listing (an ask) for a card's ungraded printing,
 * read from TCGCSV — the free mirror of TCGplayer's pricing API. `lowPrice` is
 * the lowest live listing for that product/finish; that IS a "for sale" price, so
 * no scraping is needed. TCGplayer has no graded market, so this only informs the
 * ungraded (NM) state.
 *
 * Prices are fetched per group and cached, so many cards in the same set share
 * one request. Returns null when the card has no TCGplayer product on file.
 */
class TcgplayerLowPrice
{
    /** Our `variant` → the TCGplayer subtype name it lists under. */
    private const VARIANT_SUBTYPE = [
        'normal' => ['Normal', 'Foil', 'Cold Foil'],
        'holo' => ['Holofoil', 'Foil', 'Cold Foil'],
        'reverse_holo' => ['Reverse Holofoil'],
    ];

    public function __construct(protected TcgcsvClient $client) {}

    /** Lowest current TCGplayer ask (cents) for the item's printing, or null. */
    public function forItem(CatalogItem $item): ?int
    {
        $productId = $item->external_ids['tcgplayer_product_id'] ?? null;
        $groupId = $item->set?->external_ids['tcgplayer_group_id'] ?? null;

        if ($productId === null || $groupId === null) {
            return null;
        }

        $category = $this->category($item);

        try {
            $prices = Cache::remember(
                "tcgcsv:prices:{$category}:{$groupId}",
                now()->addHours(12),
                fn () => $this->client->prices((int) $groupId, $category),
            );
        } catch (Throwable) {
            return null; // TCGCSV hiccup — eBay asks still carry the for-sale value
        }

        $rows = array_values(array_filter(
            $prices,
            fn ($p) => (string) ($p['productId'] ?? '') === (string) $productId,
        ));

        if ($rows === []) {
            return null;
        }

        $variant = $item->attributes['variant'] ?? 'normal';
        $wanted = self::VARIANT_SUBTYPE[$variant] ?? ['Normal'];

        // The row for this printing's finish, else fall back to the cheapest row.
        $row = null;
        foreach ($wanted as $subtype) {
            $row = collect($rows)->firstWhere('subTypeName', $subtype);
            if ($row !== null) {
                break;
            }
        }
        $row ??= collect($rows)->sortBy('lowPrice')->first();

        $low = $row['lowPrice'] ?? $row['midPrice'] ?? $row['marketPrice'] ?? null;

        return $low ? (int) round((float) $low * 100) : null;
    }

    /** TCGplayer category the card's group lives under (Lorcana / EN vs JP Pokémon). */
    protected function category(CatalogItem $item): int
    {
        if ($item->productLine?->slug === 'lorcana') {
            return TcgcsvClient::LORCANA;
        }

        return ($item->attributes['language'] ?? 'en') === 'ja'
            ? TcgcsvClient::POKEMON_JAPAN
            : TcgcsvClient::POKEMON;
    }
}
