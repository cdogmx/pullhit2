<?php

namespace App\Actions\Collection;

use App\Models\CollectionItem;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Compute a user's portfolio: total market value (from market_values, per each
 * holding's priced state), total cost basis (Σ lots), unrealized P&L, allocation
 * by set, and top gainers/decliners. Pure aggregation — reads market_values only.
 *
 * P&L is UNREALIZED (held positions). Realized gains (FIFO/LIFO/spec-ID on sale)
 * are deferred until selling exists. The gain % is measured against the cost
 * basis of holdings that actually have a computed value, so an un-valued card
 * doesn't distort the percentage.
 */
class BuildPortfolio
{
    /**
     * @return array{items: Collection<int, CollectionItem>, summary: array<string, mixed>, allocation: array<int, array<string, mixed>>, gainers: array<int, array<string, mixed>>, decliners: array<int, array<string, mixed>>}
     */
    public function __invoke(User $user, ?int $collectionId = null, ?string $folder = null): array
    {
        $items = $user->collectionItems()
            ->when($collectionId, fn ($q) => $q->where('collection_id', $collectionId))
            ->when($folder !== null, fn ($q) => $q->where('folder', $folder))
            ->with(['catalogItem.set', 'catalogItem.productLine', 'catalogItem.vertical', 'catalogItem.marketValues', 'gradingCompany', 'acquisitionLots'])
            ->get();

        // Memoize the per-holding numbers once.
        $rows = $items->map(function (CollectionItem $ci) {
            $unit = $ci->currentUnitValue();
            $value = $unit !== null ? $unit * $ci->quantity : null;
            $cost = $ci->costBasisCents();

            return [
                'ci' => $ci,
                'value' => $value,
                'cost' => $cost,
                'gain' => $value !== null ? $value - $cost : null,
                'pct' => $value !== null && $cost > 0 ? round(($value - $cost) / $cost * 100, 1) : null,
            ];
        });

        $valued = $rows->filter(fn ($r) => $r['value'] !== null);

        $totalValue = (int) $valued->sum('value');
        $totalCost = (int) $rows->sum('cost');
        $costOfValued = (int) $valued->sum('cost');
        $unrealized = $totalValue - $costOfValued;

        $summary = [
            'total_value' => $totalValue,
            'total_cost' => $totalCost,
            'cost_basis_valued' => $costOfValued,
            'unrealized_gain' => $unrealized,
            'unrealized_pct' => $costOfValued > 0 ? round($unrealized / $costOfValued * 100, 1) : null,
            'item_count' => $rows->count(),
            'card_count' => (int) $items->sum('quantity'),
            'valued_count' => $valued->count(),
            'currency' => 'USD',
        ];

        // Allocation by set (valued holdings), largest first. Each carries the
        // brand (product line) so the UI can show "Brand → Set".
        $allocation = $valued
            ->groupBy(fn ($r) => $r['ci']->catalogItem?->set?->name ?? 'Other')
            ->map(function (Collection $group, string $label) use ($totalValue) {
                $value = (int) $group->sum('value');

                $first = $group->first()['ci']->catalogItem;

                return [
                    'label' => $label,
                    'brand' => $first?->productLine?->name,
                    // Slugs let the UI link the row to the set's browse page
                    // (null for the catch-all "Other" bucket → not a link).
                    'brand_slug' => $first?->productLine?->slug,
                    'set_slug' => $first?->set?->slug,
                    'value' => $value,
                    'pct' => $totalValue > 0 ? round($value / $totalValue * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        $movers = fn (Collection $set) => $set->map(fn ($r) => [
            'id' => $r['ci']->id,
            'name' => $r['ci']->catalogItem?->name,
            'number' => $r['ci']->catalogItem?->number,
            // Canonical card path so the UI links the mover (id is the collection
            // item's, not the catalog item's — never use it as a card link).
            'url' => $r['ci']->catalogItem?->path(),
            'state' => $r['ci']->stateLabel(),
            'gain' => $r['gain'],
            'pct' => $r['pct'],
        ])->values()->all();

        $withGain = $valued->filter(fn ($r) => $r['gain'] !== null);

        return [
            'items' => $items,
            'summary' => $summary,
            'allocation' => $allocation,
            'gainers' => $movers($withGain->filter(fn ($r) => $r['gain'] > 0)->sortByDesc('gain')->take(5)),
            'decliners' => $movers($withGain->filter(fn ($r) => $r['gain'] < 0)->sortBy('gain')->take(5)),
        ];
    }
}
