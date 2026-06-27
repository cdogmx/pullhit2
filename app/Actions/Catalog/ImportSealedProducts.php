<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\Set;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Support\Collection;

/**
 * Import a set's SEALED products (booster boxes, ETBs, packs, tins, …) from
 * TCGCSV — the free TCGplayer mirror already used for card images. Sealed
 * products are the ones with no collector number; the product name maps to our
 * sealed_type, the TCGplayer image is stored in our bucket, and the market price
 * seeds an estimated value. Idempotent on the product identity; re-runnable.
 */
class ImportSealedProducts
{
    public function __construct(
        protected TcgcsvClient $tcgcsv,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @return array{sealed: int, created: int, images: int, valued: int, skipped: array<int, string>, sample: array<int, array<string, mixed>>}
     */
    public function __invoke(Set $set, int $groupId, int $category = TcgcsvClient::POKEMON, bool $dryRun = false, bool $withImages = true): array
    {
        $set->loadMissing('productLine.vertical');
        $line = $set->productLine;
        $vertical = $line->vertical;

        $products = $this->tcgcsv->products($groupId, $category);
        $pricesByProduct = collect($this->tcgcsv->prices($groupId, $category))->groupBy('productId');

        $created = 0;
        $imageCount = 0;
        $valued = 0;
        $skipped = [];
        $sample = [];

        foreach ($products as $product) {
            // Singles carry a collector "Number" in extendedData; sealed don't.
            if ($this->extended($product, 'Number') !== null) {
                continue;
            }

            $name = (string) ($product['name'] ?? '');
            $type = $this->sealedType($name);
            if ($type === null) {
                $skipped[] = $name; // a non-sealed no-number product (code card, accessory)

                continue;
            }

            $priceCents = $this->marketCents($pricesByProduct->get($product['productId'] ?? null));

            if ($dryRun) {
                if (count($sample) < 15) {
                    $sample[] = ['name' => $name, 'type' => $type, 'price' => $priceCents, 'has_image' => ! empty($product['imageUrl'])];
                }
                $created++;
                $valued += $priceCents !== null ? 1 : 0;

                continue;
            }

            $productId = (string) ($product['productId'] ?? '');
            $imageUrl = null;
            if ($withImages) {
                $imageUrl = $this->images->store($set->slug, 'tcg-'.$productId, $product['imageUrl'] ?? null, $line->slug);
                if ($imageUrl) {
                    $imageCount++;
                }
            }

            $item = ($this->create)(
                vertical: $vertical,
                productLine: $line,
                set: $set,
                itemType: ItemType::Sealed,
                name: $name,
                number: null,
                attributes: array_filter([
                    'sealed_type' => $type,
                    'language' => $set->language ?: 'en',
                ], fn ($v) => $v !== null && $v !== ''),
                externalIds: array_filter(['tcgplayer_product_id' => $productId ?: null]),
                primaryImagePath: $imageUrl,
            );
            $created++;

            if ($priceCents !== null && $priceCents > 0) {
                ($this->seed)($item, $priceCents);
                $valued++;
            }
        }

        return [
            'sealed' => $created,
            'created' => $created,
            'images' => $imageCount,
            'valued' => $valued,
            'skipped' => $skipped,
            'sample' => $sample,
        ];
    }

    /**
     * Map a TCGplayer product name to one of our sealed_type facets. Ordered
     * most-specific first; returns null for products that aren't sealed (so code
     * cards / accessories with no number are skipped, not imported as "other").
     */
    protected function sealedType(string $name): ?string
    {
        $n = mb_strtolower($name);

        // Digital PTCGO/PTCG-Live code cards are not sealed product.
        if (str_contains($n, 'code card')) {
            return null;
        }

        return match (true) {
            str_contains($n, 'booster box case') => 'booster_box_case',
            str_contains($n, 'booster box') => 'booster_box',
            str_contains($n, 'build') && str_contains($n, 'battle') => 'build_and_battle',
            str_contains($n, 'elite trainer box') => 'elite_trainer_box',
            str_contains($n, 'sleeved booster pack') => 'sleeved_booster_pack',
            str_contains($n, 'booster bundle') => 'booster_bundle',
            str_contains($n, 'booster pack') => 'booster_pack',
            str_contains($n, 'checklane') => 'checklane',
            str_contains($n, 'blister') => 'blister',
            str_contains($n, 'tin') => 'tin',
            str_contains($n, 'collection') || str_contains($n, 'collector') => 'collection',
            str_contains($n, 'bundle') => 'bundle',
            // Generic sealed signals we haven't pinned to a precise type.
            str_contains($n, 'booster') || str_contains($n, 'box') || str_contains($n, 'pack')
                || str_contains($n, 'display') || str_contains($n, 'deck') => 'other',
            default => null,
        };
    }

    /** Market price (cents) from a product's TCGCSV price rows, or null. */
    protected function marketCents(?Collection $prices): ?int
    {
        $row = ($prices ?? collect())->first(fn ($p) => ($p['marketPrice'] ?? null) !== null)
            ?? ($prices ?? collect())->first();

        $dollars = $row['marketPrice'] ?? $row['midPrice'] ?? null;

        return $dollars !== null ? (int) round((float) $dollars * 100) : null;
    }

    /** @param  array<string, mixed>  $product */
    protected function extended(array $product, string $name): ?string
    {
        foreach ($product['extendedData'] ?? [] as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field['value'] ?? null;
            }
        }

        return null;
    }
}
