<?php

namespace App\Actions\Collection;

use App\Models\CatalogItem;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\User;

/**
 * Build a collector-friendly CSV of a user's holdings — name, set, state,
 * quantity, value, and cost basis. Money is rendered in dollars (the file is for
 * humans). Pure data: returns headers + rows; the controller streams them.
 * Includes the catalog id so an export can round-trip back through CSV import.
 */
class ExportCollectionCsv
{
    /**
     * @param  Collection|null  $collection  one collection, or null for every holding
     * @return array{headers: list<string>, rows: list<list<string|int>>}
     */
    public function __invoke(User $user, ?Collection $collection = null): array
    {
        $items = CollectionItem::query()
            ->where('user_id', $user->id)
            // Scoped when a collection is named. Someone viewing "For sale" and
            // pressing Export expects that list, not every card they own —
            // and on a large account the difference is thousands of rows.
            ->when($collection, fn ($q) => $q->where('collection_id', $collection->id))
            ->with(['catalogItem.set', 'catalogItem.marketValues', 'gradingCompany', 'acquisitionLots'])
            ->get();

        $headers = [
            'Catalog ID', 'Name', 'Set', 'Number', 'Rarity', 'Variant', 'Language',
            'State', 'Quantity', 'Unit value', 'Market value', 'Cost basis',
            'Unrealized gain', 'Currency', 'Folder', 'Notes',
        ];

        $rows = $items->map(function (CollectionItem $item) {
            $unit = $item->currentUnitValue();
            $market = $unit !== null ? $unit * $item->quantity : null;
            $cost = $item->costBasisCents();
            $catalog = $item->catalogItem;

            return [
                $catalog?->id ?? '',
                $catalog?->name ?? '',
                $catalog?->set?->code ?? $catalog?->set?->name ?? '',
                $catalog?->number ?? '',
                $catalog?->rarity ?? '',
                // Which printing, spelled the way a person reads it. It matters
                // more than it looks: a foil and its normal sibling share a name
                // and a number, and on Lorcana the foil carries a premium — so a
                // row without this cannot be told from its twin.
                self::printing($catalog),
                $catalog?->language ?? '',
                $item->stateLabel(),
                $item->quantity,
                self::dollars($unit),
                self::dollars($market),
                self::dollars($cost),
                self::dollars($market !== null ? $market - $cost : null),
                'USD',
                $item->folder ?? '',
                $item->notes ?? '',
            ];
        })->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * "reverse_holo" → "Reverse Holo". Empty for a card with no variant.
     *
     * Left out for a plain Normal printing: on a card with one printing it is
     * noise in every row, and the column is there to distinguish the ones that
     * have siblings.
     */
    private static function printing(?CatalogItem $catalog): string
    {
        $variant = $catalog?->variant ?? ($catalog?->attributes['variant'] ?? null);

        if ($variant === null || $variant === '' || $variant === 'normal') {
            return '';
        }

        return ucwords(str_replace('_', ' ', (string) $variant));
    }

    /** Cents → plain dollar string (e.g. 84.59); empty when null. */
    private static function dollars(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
