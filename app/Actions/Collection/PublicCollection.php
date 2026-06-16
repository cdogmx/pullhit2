<?php

namespace App\Actions\Collection;

use App\Models\Collection;
use App\Models\CollectionItem;

/**
 * Build the PUBLIC view of a named collection: cards, states, quantities, and
 * market values, sorted by value. Deliberately omits cost basis and P&L — those
 * stay private even when the collection is public.
 */
class PublicCollection
{
    /**
     * @return array{owner: array<string, mixed>, collection: array<string, mixed>, summary: array<string, mixed>, holdings: array<int, array<string, mixed>>}
     */
    public function __invoke(Collection $collection): array
    {
        $user = $collection->user;
        $items = $collection->items()
            ->with(['catalogItem.set', 'catalogItem.marketValues', 'gradingCompany'])
            ->get();

        $rows = $items->map(function (CollectionItem $ci) {
            $unit = $ci->currentUnitValue();
            $value = $unit !== null ? $unit * $ci->quantity : null;
            $catalog = $ci->catalogItem;

            return [
                'value' => $value,
                'holding' => [
                    'catalog_item_id' => $catalog?->id,
                    'name' => $catalog?->display_name,
                    'number' => $catalog?->number,
                    'image_url' => $catalog?->primary_image_path
                        ?? ($catalog?->external_ids['ptcgio_image'] ?? null),
                    'set' => $catalog?->set?->name,
                    'state_label' => $ci->stateLabel(),
                    'quantity' => $ci->quantity,
                    'unit_value' => $unit,
                    'market_value' => $value,
                    'currency' => 'USD',
                ],
            ];
        });

        $totalValue = (int) $rows->sum('value');

        return [
            'owner' => [
                // Public pages identify the owner by their handle, never their real name.
                'username' => $user->username,
            ],
            'collection' => [
                'name' => $collection->name,
                'is_default' => $collection->is_default,
            ],
            'summary' => [
                'total_value' => $totalValue,
                'item_count' => $items->count(),
                'card_count' => (int) $items->sum('quantity'),
                'currency' => 'USD',
            ],
            'holdings' => $rows
                ->sortByDesc('value')
                ->pluck('holding')
                ->values()
                ->all(),
        ];
    }
}
