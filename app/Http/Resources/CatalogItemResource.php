<?php

namespace App\Http\Resources;

use App\Models\CatalogItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CatalogItem
 */
class CatalogItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attributes = $this->attributes ?? [];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'number' => $this->number,
            'item_type' => $this->item_type->value,
            'language' => $this->language,
            'rarity' => $attributes['rarity'] ?? null,
            'variant' => $attributes['variant'] ?? null,
            'image_url' => $this->primary_image_path ?? ($this->external_ids['ptcgio_image'] ?? null),
            'base_key' => $this->base_key,
            // All vertical-specific facets (illustrator, hp, type, sealed_type, …).
            'attributes' => $attributes,
            // Present only when grouping by base card (withCount('variants')).
            'variants_count' => $this->whenCounted('variants'),
            // The card's printings (detail view); each is a lightweight sibling.
            'variants' => CatalogItemResource::collection($this->whenLoaded('variants')),
            'set' => $this->whenLoaded('set', fn () => [
                'slug' => $this->set->slug,
                'name' => $this->set->name,
                'code' => $this->set->code,
            ]),
            'product_line' => $this->whenLoaded('productLine', fn () => [
                'slug' => $this->productLine->slug,
                'name' => $this->productLine->name,
            ]),
            'vertical' => $this->whenLoaded('vertical', fn () => [
                'slug' => $this->vertical->slug,
                'name' => $this->vertical->name,
            ]),
        ];
    }
}
