<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\SaleObservation;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A weekly-median price-history series for one priced state (raw condition or a
 * specific graded slab) over a window — drawn from REAL sold observations when
 * they yield a trend, falling back to the synthetic estimate only when a card is
 * too thin to plot honestly. Weekly buckets give a clean PriceCharting-style
 * trend; `n` is the sold count per bucket for the tooltip. The state filter
 * mirrors the price-breakdown drawer, so the chart and the comps agree. Powers
 * the card-page "Price history" chart.
 */
class PriceHistory
{
    /**
     * @param  string|null  $stateKey  a market_values.state_key (e.g. "NM", "PSA-10");
     *                                 null → the card's default (headline) state.
     * @return array{points: array<int, array{t: string, price: int, n: int}>, estimated: bool}
     */
    public function __invoke(CatalogItem $item, int $days = 365, ?string $stateKey = null): array
    {
        $state = $stateKey !== null
            ? $item->marketValues()->where('state_key', $stateKey)->first()
            : $item->defaultMarketValue()->first();

        $base = $item->saleObservations()
            ->where('is_outlier', false)
            ->where('observed_at', '>=', Carbon::now()->subDays($days));

        // Match the chosen state's exact (condition, grader, grade) triple — the
        // same filter the breakdown drawer uses. With no state at all, fall back
        // to the raw (ungraded) aggregate so a card always has a default series.
        $state
            ? $this->applyState($base, $state)
            : $base->whereNull('grading_company_id');

        // Prefer real sold data: if it yields a real trend (≥2 weekly points),
        // use it. Otherwise fall back to the synthetic estimate, flagged.
        $realPoints = $this->bucket((clone $base)->where('is_synthetic', false)->get(['observed_at', 'price']));
        if (count($realPoints) >= 2) {
            return ['points' => $realPoints, 'estimated' => false];
        }

        return ['points' => $this->bucket($base->get(['observed_at', 'price'])), 'estimated' => true];
    }

    /**
     * Narrow the observation query to the priced state's exact identity, the same
     * way GetPricedStateBreakdown does (so the chart and the comps line up).
     *
     * @param  HasMany<SaleObservation, CatalogItem>  $query
     */
    private function applyState(HasMany $query, MarketValue $state): void
    {
        $state->condition === null
            ? $query->whereNull('condition')
            : $query->where('condition', $state->condition->value);
        $state->grading_company_id === null
            ? $query->whereNull('grading_company_id')
            : $query->where('grading_company_id', $state->grading_company_id);
        $state->grade === null
            ? $query->whereNull('grade')
            : $query->where('grade', $state->grade);
    }

    /**
     * Group observations into weekly median points.
     *
     * @param  Collection<int, SaleObservation>  $observations
     * @return array<int, array{t: string, price: int, n: int}>
     */
    private function bucket(Collection $observations): array
    {
        if ($observations->count() < 2) {
            return [];
        }

        return $observations
            ->groupBy(fn ($o) => $o->observed_at->copy()->startOfWeek()->toDateString())
            ->map(function (Collection $group, string $week) {
                $prices = $group->pluck('price')->sort()->values();
                $n = $prices->count();
                $median = $n % 2 === 1
                    ? (int) $prices[intdiv($n, 2)]
                    : (int) round(((int) $prices[$n / 2 - 1] + (int) $prices[$n / 2]) / 2);

                return ['t' => $week, 'price' => $median, 'n' => $n];
            })
            ->sortKeys()
            ->values()
            ->all();
    }
}
