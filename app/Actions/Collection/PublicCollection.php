<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;
use App\Models\User;

/**
 * Build the PUBLIC view of a user's collection: cards, states, quantities, and
 * market values, sorted by value. Deliberately omits cost basis and P&L — those
 * stay private even when the collection is public.
 */
class PublicCollection
{
    /**
     * @return array{owner: array<string, mixed>, summary: array<string, mixed>, holdings: array<int, array<string, mixed>>}
     */
    public function __invoke(User $user): array
    {
        $items = $user->collectionItems()
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
                'name' => $user->name,
                'username' => $user->username,
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
