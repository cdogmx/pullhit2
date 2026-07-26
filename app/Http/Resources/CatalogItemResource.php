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
            'display_name' => $this->display_name,
            'number' => $this->number,
            'slug' => $this->slug,
            // Canonical /{brand}/{set}/{card} path; null when relations/slug are
            // missing, so the client falls back to /catalog/{id}.
            'url' => $this->path(),
            'item_type' => $this->item_type->value,
            'language' => $this->language,
            'rarity' => $attributes['rarity'] ?? null,
            'variant' => $attributes['variant'] ?? null,
            'image_url' => $this->primary_image_path ?? ($this->external_ids['ptcgio_image'] ?? null),
            'base_key' => $this->base_key,
            // Generic product metadata (sealed products): MSRP (cents) + release
            // date. "Where to buy" links come from the deals tracker (see the show
            // controller's whereToBuy), not this resource.
            'msrp' => $this->msrp,
            'msrp_source' => $this->msrp_source,
            'released_at' => $this->released_at?->toDateString(),
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
            // Headline value for lists (null when no comps). When browsing a graded
            // state, the graded value is loaded + shown in place of the raw one.
            'market_value' => $this->headlineMarketValue($request),
            // Every priced state (raw + graded) for the detail page.
            'market_values' => $this->whenLoaded(
                'marketValues',
                fn () => MarketValueResource::collection($this->marketValues)->resolve($request),
            ),
            'set' => $this->whenLoaded('set', fn () => [
                'slug' => $this->set->slug,
                'name' => $this->set->name,
                'code' => $this->set->code,
                // The era the set belongs to — the breadcrumb's missing rung
                // between the brand and the set, matching browse's drill-down.
                'series' => $this->set->series,
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

    /**
     * The value shown in lists: the graded state's value when browsing a grade
     * (SearchCatalog loaded gradedMarketValue), otherwise the raw headline value.
     * Absent from the payload when neither relation is loaded.
     */
    private function headlineMarketValue(Request $request): mixed
    {
        if ($this->relationLoaded('gradedMarketValue')) {
            return $this->gradedMarketValue
                ? (new MarketValueResource($this->gradedMarketValue))->resolve($request)
                : null;
        }

        return $this->whenLoaded(
            'defaultMarketValue',
            fn () => $this->defaultMarketValue
                ? (new MarketValueResource($this->defaultMarketValue))->resolve($request)
                : null,
        );
    }
}
