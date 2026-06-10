<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use Illuminate\Support\Carbon;

/**
 * A small price-trend series for a card's ungraded (raw) state — the dated,
 * non-outlier sale observations over a recent window, downsampled for a
 * sparkline. It's a trend illustration, not a quote (may include synthetic comps).
 */
class PriceHistory
{
    public function __invoke(CatalogItem $item, int $days = 90, int $maxPoints = 30): array
    {
        $points = $item->saleObservations()
            ->whereNull('grading_company_id')
            ->where('is_outlier', false)
            ->where('observed_at', '>=', Carbon::now()->subDays($days))
            ->orderBy('observed_at')
            ->get(['observed_at', 'price'])
            ->map(fn ($o) => ['t' => $o->observed_at->toDateString(), 'price' => (int) $o->price])
            ->values();

        // Downsample to at most $maxPoints by striding.
        if ($points->count() > $maxPoints) {
            $stride = (int) ceil($points->count() / $maxPoints);
            $points = $points->filter(fn ($_, $i) => $i % $stride === 0)->values();
        }

        return $points->all();
    }
}
