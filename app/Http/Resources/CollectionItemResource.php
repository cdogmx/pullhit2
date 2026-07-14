<?php

namespace App\Http\Resources;

use App\Models\CollectionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CollectionItem
 */
class CollectionItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $unit = $this->currentUnitValue();
        $marketValue = $unit !== null ? $unit * $this->quantity : null;
        $cost = $this->costBasisCents();

        return [
            'id' => $this->id,
            'collection_id' => $this->collection_id,
            'state_key' => $this->stateKey(),
            'state_label' => $this->stateLabel(),
            'condition' => $this->condition?->value,
            'grade' => $this->grade,
            'grading_company' => $this->whenLoaded('gradingCompany', fn () => $this->gradingCompany
                ? ['id' => $this->gradingCompany->id, 'slug' => $this->gradingCompany->slug, 'name' => $this->gradingCompany->name]
                : null),
            'quantity' => $this->quantity,
            'is_for_sale' => $this->is_for_sale,
            'notes' => $this->notes,
            'folder' => $this->folder,
            'added_at' => $this->created_at?->toIso8601String(),
            // Money in integer minor units (cents).
            'unit_value' => $unit,
            'market_value' => $marketValue,
            'cost_basis' => $cost,
            'unrealized_gain' => $marketValue !== null ? $marketValue - $cost : null,
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
