<?php

namespace App\Support\Verticals;

use InvalidArgumentException;

/**
 * The full attribute schema for one vertical: which item types it supports and,
 * per item type, the facet definitions. This is the data the registry validates
 * against and that drives dynamic forms/filters.
 */
readonly class VerticalDefinition
{
    /**
     * @param  array<string, array<int, AttributeDefinition>>  $attributes  keyed by item_type
     */
    public function __construct(
        public string $slug,
        public string $name,
        public array $attributes,
    ) {}

    /**
     * @return array<int, string> the item types this vertical defines facets for
     */
    public function itemTypes(): array
    {
        return array_keys($this->attributes);
    }

    public function supportsItemType(string $itemType): bool
    {
        return array_key_exists($itemType, $this->attributes);
    }

    /**
     * Facet definitions for a given item type.
     *
     * @return array<int, AttributeDefinition>
     */
    public function attributesFor(string $itemType): array
    {
        if (! $this->supportsItemType($itemType)) {
            throw new InvalidArgumentException(
                "Vertical [{$this->slug}] does not define item type [{$itemType}]."
            );
        }

        return $this->attributes[$itemType];
    }
}
