<?php

namespace App\Support\Catalog;

use Illuminate\Support\Str;

/**
 * Computes the catalog_item dedup key: sha256 of (vertical, product_line, set,
 * canonical facets) per §4.1. Deterministic and stable — the same logical card
 * always hashes the same, so importers can upsert idempotently and EN/JP/KR
 * variants (different language facet) never collide.
 */
final class IdentityHash
{
    /**
     * @param  array<string, mixed>  $attributes  the validated, declared facets
     */
    public static function compute(
        string $verticalSlug,
        string $productLineSlug,
        ?string $setKey,
        string $itemType,
        string $name,
        ?string $number,
        array $attributes,
    ): string {
        // Canonical facets: sort by key so attribute order never affects the hash.
        ksort($attributes);

        $canonical = [
            'vertical' => $verticalSlug,
            'product_line' => $productLineSlug,
            'set' => $setKey,
            'item_type' => $itemType,
            'name' => Str::lower(trim($name)),
            'number' => $number,
            'attributes' => $attributes,
        ];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
