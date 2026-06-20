<?php

namespace App\Http\Resources;

use App\Models\WishlistItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WishlistItem
 */
class WishlistItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $current = $this->currentValue();

        return [
            'id' => $this->id,
            // Money in integer minor units (cents).
            'target_price' => $this->target_price,
            'current_value' => $current,
            'below_target' => $this->target_price !== null && $current !== null && $current <= $this->target_price,
            'notes' => $this->notes,
            'currency' => 'USD',
            'catalog_item' => $this->whenLoaded('catalogItem', fn () => [
                'id' => $this->catalogItem->id,
                'name' => $this->catalogItem->name,
                'display_name' => $this->catalogItem->display_name,
                'number' => $this->catalogItem->number,
                'url' => $this->catalogItem->path(),
                'image_url' => $this->catalogItem->primary_image_path
                    ?? ($this->catalogItem->external_ids['ptcgio_image'] ?? null),
                'set' => $this->catalogItem->relationLoaded('set') && $this->catalogItem->set
                    ? ['name' => $this->catalogItem->set->name, 'code' => $this->catalogItem->set->code]
                    : null,
            ]),
        ];
    }
}
