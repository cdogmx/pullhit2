<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;

/**
 * Create a sealed product (booster box, ETB, …) in a set via the shared
 * CreateCatalogItem, optionally seeding an estimated market value from a known
 * price. Used by the admin sealed-product form. Idempotent on identity_hash.
 */
class AddSealedProduct
{
    public function __construct(
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated: name, sealed_type, language, pack_count?, price_cents?, image_url?
     */
    public function __invoke(Set $set, array $data): CatalogItem
    {
        $set->loadMissing('productLine.vertical');
        $productLine = $set->productLine;
        $vertical = $productLine->vertical;

        $item = ($this->create)(
            vertical: $vertical,
            productLine: $productLine,
            set: $set,
            itemType: ItemType::Sealed,
            name: $data['name'],
            number: null,
            attributes: array_filter([
                'sealed_type' => $data['sealed_type'],
                'language' => $data['language'],
                'pack_count' => $data['pack_count'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            externalIds: [],
            primaryImagePath: $data['image_url'] ?? null,
        );

        if (! empty($data['price_cents'])) {
            ($this->seed)($item, (int) $data['price_cents']);
        }

        return $item;
    }
}
