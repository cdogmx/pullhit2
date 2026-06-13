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
            // Generic product metadata (sealed products): MSRP (cents), release
            // date, and "where to buy" retailer links (each with its own price).
            'msrp' => $this->msrp,
            'released_at' => $this->released_at?->toDateString(),
            'retailer_links' => $this->retailer_links,
            // All vertical-specific facets (illustrator, hp, type, sealed_type, …).
            'attributes' => $attributes,
            // Present only when grouping by base card (withCount('variants')).
            'variants_count' => $this->whenCounted('variants'),
            // The card's printings (detail view); each is a lightweight sibling.
            // Nested resources are resolved to plain arrays so no serialization
            // path (Inertia merge props in particular) wraps them in a `data` key.
            'variants' => $this->whenLoaded(
                'variants',
                fn () => CatalogItemResource::collection($this->variants)->resolve($request),
            ),
            // Headline ungraded value for lists (null when no comps).
            'market_value' => $this->whenLoaded(
                'defaultMarketValue',
                fn () => $this->defaultMarketValue
                    ? (new MarketValueResource($this->defaultMarketValue))->resolve($request)
                    : null,
            ),
            // Every priced state (raw + graded) for the detail page.
            'market_values' => $this->whenLoaded(
                'marketValues',
                fn () => MarketValueResource::collection($this->marketValues)->resolve($request),
            ),
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
