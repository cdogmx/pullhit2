<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Catalog\ItemIdentity;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Update a sealed product in place: re-validate its facets, recompute the
 * identity hash (name/sealed_type/language feed it), refresh metadata (MSRP,
 * release date, retailer links, image), and re-seed an estimated value when a
 * price is given. Rejects an edit that would collide with another product.
 */
class UpdateSealedProduct
{
    public function __construct(
        protected VerticalRegistry $registry,
        protected SeedSyntheticValuation $seed,
        protected ItemIdentity $identity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  same shape as AddSealedProduct
     */
    public function __invoke(CatalogItem $item, array $data): CatalogItem
    {
        $item->loadMissing(['vertical', 'productLine', 'set']);

        $name = $data['name'];
        $attributes = $this->registry->validate($item->vertical->slug, ItemType::Sealed->value, array_filter([
            'sealed_type' => $data['sealed_type'],
            'language' => $data['language'],
            'pack_count' => $data['pack_count'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        ['identity_hash' => $identityHash, 'base_key' => $baseKey] = $this->identity->for(
            verticalSlug: $item->vertical->slug,
            productLineSlug: $item->productLine->slug,
            setKey: $item->set?->slug,
            itemType: ItemType::Sealed->value,
            name: $name,
            number: null,
            attributes: $attributes,
        );

        $collision = CatalogItem::where('identity_hash', $identityHash)
            ->whereKeyNot($item->id)
            ->exists();
        if ($collision) {
            throw ValidationException::withMessages(['name' => 'A sealed product with those identifiers already exists.']);
        }

        $item->forceFill([
            'name' => $name,
            'attributes' => $attributes,
            'identity_hash' => $identityHash,
            'base_key' => $baseKey,
            'msrp' => $data['msrp_cents'] ?? null,
            'released_at' => $data['released_at'] ?? null,
            // Keep the existing image when the field is left blank.
            'primary_image_path' => ! empty($data['image_url']) ? $data['image_url'] : $item->primary_image_path,
        ])->save();

        if (! empty($data['price_cents'])) {
            ($this->seed)($item, (int) $data['price_cents']);
        }

        return $item;
    }
}
