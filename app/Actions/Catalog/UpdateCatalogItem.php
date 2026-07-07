<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Support\Catalog\IdentityHash;
use App\Support\Catalog\StampMatcher;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Admin edit of a catalog_item in place. Re-validates attributes against the
 * vertical registry and recomputes identity_hash/base_key (name/number/variant/
 * facets feed the hash). Rejects an edit that would collide with another item.
 */
class UpdateCatalogItem
{
    public function __construct(
        protected VerticalRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $data  editable fields
     *
     * @throws ValidationException
     */
    public function __invoke(CatalogItem $item, array $data): CatalogItem
    {
        $item->loadMissing(['vertical', 'productLine', 'set']);

        $name = $data['name'] ?? $item->name;
        $number = array_key_exists('number', $data) ? ($data['number'] ?: null) : $item->number;

        // Start from current facets, override the provided editable ones.
        $attributes = array_merge($item->attributes ?? [], array_filter([
            'rarity' => $data['rarity'] ?? null,
            'variant' => $data['variant'] ?? null,
            'stamp' => ! empty($data['stamp']) ? (new StampMatcher)->canonical($data['stamp']) : null,
            'illustrator' => $data['illustrator'] ?? null,
            'hp' => isset($data['hp']) && $data['hp'] !== '' ? (int) $data['hp'] : null,
            'type' => $data['type'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $validated = $this->registry->validate($item->vertical->slug, $item->item_type->value, $attributes);

        $hashArgs = [
            'verticalSlug' => $item->vertical->slug,
            'productLineSlug' => $item->productLine->slug,
            'setKey' => $item->set?->slug, // stable slug, never mutable `code` (see CreateCatalogItem)
            'itemType' => $item->item_type->value,
            'name' => $name,
            'number' => $number,
        ];

        $identityHash = IdentityHash::compute(...$hashArgs, attributes: $validated);

        $collision = CatalogItem::where('identity_hash', $identityHash)
            ->whereKeyNot($item->id)
            ->exists();
        if ($collision) {
            throw ValidationException::withMessages(['name' => 'A card with those identifiers already exists.']);
        }

        $variantKeys = $this->registry->get($item->vertical->slug)->variantDefiningKeys($item->item_type->value);
        $baseKey = IdentityHash::compute(...$hashArgs, attributes: array_diff_key($validated, array_flip($variantKeys)));

        $item->forceFill([
            'name' => $name,
            'number' => $number,
            'attributes' => $validated,
            'primary_image_path' => array_key_exists('primary_image_path', $data)
                ? ($data['primary_image_path'] ?: null)
                : $item->primary_image_path,
            'identity_hash' => $identityHash,
            'base_key' => $baseKey,
        ])->save();

        return $item;
    }
}
