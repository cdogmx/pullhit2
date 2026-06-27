<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\SaleObservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A weekly-median price-history series for a card's ungraded (raw) state over a
 * window — drawn from REAL sold observations when they yield a trend, falling
 * back to the synthetic estimate only when a card is too thin to plot honestly.
 * Weekly buckets give a clean PriceCharting-style trend; `n` is the sold count
 * per bucket for the tooltip. Powers the card-page "Price history" chart.
 */
class PriceHistory
{
    /**
     * @return array{points: array<int, array{t: string, price: int, n: int}>, estimated: bool}
     */
    public function __invoke(CatalogItem $item, int $days = 365): array
    {
        $base = $item->saleObservations()
            ->whereNull('grading_company_id')
            ->where('is_outlier', false)
            ->where('observed_at', '>=', Carbon::now()->subDays($days));

        // Prefer real sold data: if it yields a real trend (≥2 weekly points),
        // use it. Otherwise fall back to the synthetic estimate, flagged.
        $realPoints = $this->bucket((clone $base)->where('is_synthetic', false)->get(['observed_at', 'price']));
        if (count($realPoints) >= 2) {
            return ['points' => $realPoints, 'estimated' => false];
        }

        return ['points' => $this->bucket($base->get(['observed_at', 'price'])), 'estimated' => true];
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
