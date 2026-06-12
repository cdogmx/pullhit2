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

        $hashArgs = [
            'verticalSlug' => $vertical->slug,
            'productLineSlug' => $productLine->slug,
            // The set's stable, language-specific slug — never `code`, which is
            // pulled from a mutable ptcgoCode (often added/changed after release)
            // and is shared across languages. Using it would re-hash a whole set
            // on a routine re-import (silent duplication) and collide EN/JP.
            'setKey' => $set?->slug,
            'itemType' => $itemType->value,
            'name' => $name,
            'number' => $number,
        ];

        // identity_hash is unique per printing (includes variant/finish);
        // base_key drops the variant-defining facets so every printing of the
        // same card shares one group.
        $identityHash = IdentityHash::compute(...$hashArgs, attributes: $validated);

        $variantKeys = $this->registry->get($vertical->slug)->variantDefiningKeys($itemType->value);
        $baseAttributes = array_diff_key($validated, array_flip($variantKeys));
        $baseKey = IdentityHash::compute(...$hashArgs, attributes: $baseAttributes);

        $item = CatalogItem::firstOrNew(['identity_hash' => $identityHash]);

        // Merge external ids so a re-import from one source never erases ids set
        // by another (e.g. a tcgplayer_product_id attached elsewhere).
        $mergedExternalIds = array_merge($item->external_ids ?? [], $externalIds);

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
            'external_ids' => $mergedExternalIds === [] ? null : $mergedExternalIds,
            // Preserve an existing image on re-import when none is supplied (e.g.
            // a `--no-images` revalue run) — don't null out a stored S3 path.
            'primary_image_path' => $primaryImagePath ?? $item->primary_image_path,
            'identity_hash' => $identityHash,
            'base_key' => $baseKey,
        ])->save();

        return $item;
    }
}
