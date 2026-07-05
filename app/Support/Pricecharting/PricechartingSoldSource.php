<?php

namespace App\Support\Pricecharting;

use App\Models\CatalogItem;
use App\Models\PricechartingProduct;
use App\Support\Ebay\OxylabsClient;

/**
 * Fetches a catalog item's completed sales from its PriceCharting product page.
 * Resolves the page URL from the imported PriceCharting price-guide products
 * (matched by set + sealed identity), fetches WITHOUT headless render (or the
 * sold-listings table is stripped), and parses the rows. Read-only.
 */
class PricechartingSoldSource
{
    public function __construct(
        protected OxylabsClient $client,
        protected CompletedSalesParser $parser,
    ) {}

    /**
     * @return array<int, CompletedSale>
     */
    public function fetch(CatalogItem $item): array
    {
        $url = $this->resolveUrl($item);

        if ($url === null) {
            return [];
        }

        $html = $this->client->fetchHtml($url, config('valuation.ebay.geo', 'United States'), render: false);

        return $this->parser->parse($html, 'used');
    }

    /** The PriceCharting product-page URL for this item, or null if unmatched. */
    public function resolveUrl(CatalogItem $item): ?string
    {
        $product = $this->matchProduct($item);

        if ($product === null) {
            return null;
        }

        return 'https://www.pricecharting.com/game/'
            .$this->pcSlug($product->console_name).'/'.$this->pcSlug($product->product_name);
    }

    /**
     * PriceCharting's URL slug rules differ from Laravel's Str::slug: it KEEPS the
     * literal "&" (e.g. "Pokemon Scarlet & Violet 151" → "pokemon-scarlet-&-violet-151").
     * Dropping it (as Str::slug does) yields a wrong URL that serves a degraded page.
     */
    private function pcSlug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9&]+/', '-', $value);

        return trim((string) preg_replace('/-+/', '-', $value), '-');
    }

    /**
     * Find the PriceCharting product for a catalog item: an explicit pricecharting_id
     * wins; otherwise best-match a sealed price-guide product by set name + sealed
     * type + variant (Case / Pokémon Center / Plus), the same axes the comp
     * classifier guards on.
     */
    public function matchProduct(CatalogItem $item): ?PricechartingProduct
    {
        if ($pcId = $item->getAttribute('external_ids')['pricecharting_id'] ?? null) {
            return PricechartingProduct::where('pc_id', (string) $pcId)->first();
        }

        $set = $item->set;
        if (! $set) {
            return null;
        }

        $candidates = PricechartingProduct::query()
            ->where('is_sealed', true)
            ->where('console_name', 'like', '%'.$set->name.'%')
            ->get();

        $type = $item->getAttribute('attributes')['sealed_type'] ?? null;
        $name = mb_strtolower($item->name);
        $wantPc = str_contains($name, 'pokemon center') || str_contains($name, 'pokémon center');
        $wantPlus = (bool) preg_match('/\bplus\b/', $name);
        $wantCase = str_contains($name, 'case');

        foreach ($candidates as $product) {
            $p = mb_strtolower($product->product_name);

            // Variant axes must agree (a base ETB must not match the PC-exclusive).
            if ($wantPc !== (str_contains($p, 'pokemon center') || str_contains($p, 'pokémon center'))) {
                continue;
            }
            if ($wantPlus !== (bool) preg_match('/\bplus\b/', $p)) {
                continue;
            }
            if ($wantCase !== (bool) preg_match('/\bcase\b/', $p)) {
                continue;
            }
            if ($this->typeMatches($type, $p)) {
                return $product;
            }
        }

        return null;
    }

    /** Whether a PriceCharting product name denotes the item's sealed type. */
    private function typeMatches(?string $type, string $productName): bool
    {
        return match ($type) {
            'elite_trainer_box' => str_contains($productName, 'elite trainer box'),
            'booster_box', 'booster_box_case' => str_contains($productName, 'booster box'),
            'booster_pack' => str_contains($productName, 'booster pack') || str_contains($productName, 'pack'),
            'sleeved_booster_pack' => str_contains($productName, 'sleeved'),
            'booster_bundle', 'bundle' => str_contains($productName, 'bundle'),
            'build_and_battle' => str_contains($productName, 'build') && str_contains($productName, 'battle'),
            'tin' => str_contains($productName, 'tin'),
            'collection' => str_contains($productName, 'collection'),
            'blister', 'checklane' => str_contains($productName, 'blister') || str_contains($productName, 'checklane'),
            default => false,
        };
    }
}
