<?php

namespace App\Actions\Collection;

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
     * @return array{headers: list<string>, rows: list<list<string|int>>}
     */
    public function __invoke(User $user): array
    {
        $items = CollectionItem::query()
            ->where('user_id', $user->id)
            ->with(['catalogItem.set', 'catalogItem.marketValues', 'gradingCompany', 'acquisitionLots'])
            ->get();

        $headers = [
            'Catalog ID', 'Name', 'Set', 'Number', 'Language', 'State',
            'Quantity', 'Unit value', 'Market value', 'Cost basis',
            'Unrealized gain', 'Currency', 'Notes',
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
                $catalog?->language ?? '',
                $item->stateLabel(),
                $item->quantity,
                self::dollars($unit),
                self::dollars($market),
                self::dollars($cost),
                self::dollars($market !== null ? $market - $cost : null),
                'USD',
                $item->notes ?? '',
            ];
        })->all();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Cents → plain dollar string (e.g. 84.59); empty when null. */
    private static function dollars(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
