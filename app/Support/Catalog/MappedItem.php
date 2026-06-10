<?php

namespace App\Support\Catalog;

/**
 * A single catalog item (one finish/variant of one card) mapped from a
 * pokemontcg.io card, ready for CreateCatalogItem + valuation seeding.
 * Money fields are integer minor units (cents); 0 = no price available.
 */
readonly class MappedItem
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $externalIds
     */
    public function __construct(
        public string $name,
        public ?string $number,
        public array $attributes,
        public array $externalIds,
        public int $anchorCents = 0,
    ) {}
}
