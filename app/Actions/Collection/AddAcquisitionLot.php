<?php

namespace App\Actions\Collection;

use App\Models\AcquisitionLot;
use App\Models\CollectionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record one acquisition (cost-basis lot) against a collection item and keep the
 * item's quantity in sync (quantity = Σ lot quantities).
 */
class AddAcquisitionLot
{
    /** @param  array<string, mixed>  $attrs */
    public function __invoke(CollectionItem $item, array $attrs): AcquisitionLot
    {
        return DB::transaction(function () use ($item, $attrs) {
            $lot = $item->acquisitionLots()->create([
                'quantity' => max(1, (int) ($attrs['quantity'] ?? 1)),
                'unit_cost' => (int) ($attrs['unit_cost'] ?? 0),
                'fees' => (int) ($attrs['fees'] ?? 0),
                'acquired_at' => $attrs['acquired_at'] ?? Carbon::now()->toDateString(),
                'source' => $attrs['source'] ?? null,
            ]);

            $item->increment('quantity', $lot->quantity);

            return $lot;
        });
    }
}
