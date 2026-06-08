<?php

namespace App\Support\Verticals;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The Vertical registry — one of the three architecture seams (§3). Holds the
 * attribute schema for every vertical and validates catalog_item attributes
 * against it. Reused by the catalog Action (Phase 1), the importer (Phase 2),
 * and the scan-confirm flow (Phase 4). Adding a vertical = register a definition.
 */
class VerticalRegistry
{
    /** @var array<string, VerticalDefinition> */
    protected array $definitions = [];

    public function register(VerticalDefinition $definition): void
    {
        $this->definitions[$definition->slug] = $definition;
    }

    public function has(string $slug): bool
    {
        return array_key_exists($slug, $this->definitions);
    }

    public function get(string $slug): VerticalDefinition
    {
        if (! $this->has($slug)) {
            throw new InvalidArgumentException("Unknown vertical [{$slug}].");
        }

        return $this->definitions[$slug];
    }

    /** @return array<string, VerticalDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Validate a catalog_item's attributes against the vertical/item-type schema.
     * Rejects missing required facets, bad types/enum values, and unknown keys.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed> the validated attributes
     *
     * @throws ValidationException
     */
    public function validate(string $slug, string $itemType, array $attributes): array
    {
        $definitions = $this->get($slug)->attributesFor($itemType);

        $rules = [];
        $labels = [];
        foreach ($definitions as $definition) {
            $rules[$definition->key] = $definition->rules();
            $labels[$definition->key] = $definition->label;
        }

        $validator = Validator::make($attributes, $rules);
        $validator->setAttributeNames($labels);

        // Laravel does not reject unexpected keys; a facet not in the schema is
        // a real error (typo, wrong vertical, or a facet that must be declared first).
        $unknown = array_diff(array_keys($attributes), array_keys($rules));
        if ($unknown !== []) {
            foreach ($unknown as $key) {
                $validator->after(function ($validator) use ($key, $slug, $itemType) {
                    $validator->errors()->add(
                        $key,
                        "The facet [{$key}] is not defined for {$slug} {$itemType}."
                    );
                });
            }
        }

        return $validator->validate();
    }
}
