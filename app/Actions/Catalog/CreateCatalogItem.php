<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\IdentityHash;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Validate, hash, and idempotently upsert a catalog_item. The single entry point
 * for putting items into the catalog — used by hand-seeding now and by the
 * Phase 2 importer. Domain logic lives here, not in controllers/components (§2/§12).
 */
class CreateCatalogItem
{
    public function __construct(
        protected VerticalRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  vertical-specific facets
     * @param  array<string, mixed>  $externalIds  {tcgplayer_product_id, ptcgio_id, ...}
     *
     * @throws ValidationException when attributes violate the registry schema
     */
    public function __invoke(
        Vertical $vertical,
        ProductLine $productLine,
        ?Set $set,
        ItemType $itemType,
        string $name,
        ?string $number = null,
        array $attributes = [],
        array $externalIds = [],
        ?string $primaryImagePath = null,
    ): CatalogItem {
        $validated = $this->registry->validate($vertical->slug, $itemType->value, $attributes);

        $identityHash = IdentityHash::compute(
            verticalSlug: $vertical->slug,
            productLineSlug: $productLine->slug,
            setKey: $set?->code ?? $set?->slug,
            itemType: $itemType->value,
            name: $name,
            number: $number,
            attributes: $validated,
        );

        $item = CatalogItem::firstOrNew(['identity_hash' => $identityHash]);

        // forceFill: identity_hash is guarded (Action-controlled), and we set the
        // full payload deterministically so re-running is a clean upsert.
        $item->forceFill([
            'vertical_id' => $vertical->id,
            'product_line_id' => $productLine->id,
            'set_id' => $set?->id,
            'item_type' => $itemType,
            'name' => $name,
            'number' => $number,
            'attributes' => $validated,
            'external_ids' => $externalIds === [] ? null : $externalIds,
            'primary_image_path' => $primaryImagePath,
            'identity_hash' => $identityHash,
        ])->save();

        return $item;
    }
}
