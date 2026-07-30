<?php

namespace App\Support\Catalog;

use App\Models\CatalogItem;
use App\Support\Verticals\VerticalRegistry;

/**
 * Computes a catalog_item's identity_hash and base_key from the same inputs, so
 * the importers and the identity-repair command can never drift apart.
 *
 * Only identity-defining facets reach the hash. Hashing every attribute made the
 * hash a function of how much a source happened to know: pokemontcg.io fills hp,
 * type and illustrator where TCGCSV fills none, so the same printing hashed two
 * ways and upserted as two rows.
 */
final class ItemIdentity
{
    public function __construct(
        protected VerticalRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  the validated facets
     * @return array{identity_hash: string, base_key: string}
     */
    public function for(
        string $verticalSlug,
        string $productLineSlug,
        ?string $setKey,
        string $itemType,
        string $name,
        ?string $number,
        array $attributes,
    ): array {
        $definition = $this->registry->get($verticalSlug);

        $identity = $this->only($attributes, $definition->identityDefiningKeys($itemType));
        // base_key drops the printing axis so every printing of one card groups.
        $base = $this->without($identity, $definition->variantDefiningKeys($itemType));

        $args = [
            'verticalSlug' => $verticalSlug,
            // The set's stable, language-specific slug — never `code`, which is
            // pulled from a mutable ptcgoCode (often added/changed after release)
            // and is shared across languages. Using it would re-hash a whole set
            // on a routine re-import (silent duplication) and collide EN/JP.
            'productLineSlug' => $productLineSlug,
            'setKey' => $setKey,
            'itemType' => $itemType,
            'name' => $name,
            'number' => $number,
        ];

        return [
            'identity_hash' => IdentityHash::compute(...$args, attributes: $identity),
            'base_key' => IdentityHash::compute(...$args, attributes: $base),
        ];
    }

    /**
     * The keys an existing row should carry, read off the model. `$name` overrides
     * the stored name, for the repair pass that cleans it.
     *
     * @return array{identity_hash: string, base_key: string}
     */
    public function forItem(CatalogItem $item, ?string $name = null): array
    {
        $item->loadMissing(['vertical', 'productLine', 'set']);

        return $this->for(
            verticalSlug: $item->vertical->slug,
            productLineSlug: $item->productLine->slug,
            setKey: $item->set?->slug,
            itemType: $item->item_type->value,
            name: $name ?? $item->name,
            number: $item->number,
            attributes: $item->getAttribute('attributes') ?? [],
        );
    }

    /**
     * Keep the named facets, dropping nulls: a facet explicitly set to null and
     * one simply absent describe the same card and must hash alike.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function only(array $attributes, array $keys): array
    {
        return array_filter(
            array_intersect_key($attributes, array_flip($keys)),
            fn ($value): bool => $value !== null && $value !== '',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    protected function without(array $attributes, array $keys): array
    {
        return array_diff_key($attributes, array_flip($keys));
    }
}
