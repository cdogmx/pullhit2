<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Proactively pull real eBay sold comps for SEALED products by NAME — the broad
 * sweep matches by collector number, so it skips sealed entirely, leaving sealed
 * valued only when someone happens to view the page. This warms the valuable,
 * stale sealed SKUs in priority order under the shared daily Oxylabs cap, reusing
 * the per-card ingest (which now runs the sealed-aware classifier).
 */
class SweepSealedComps
{
    public function __construct(
        protected IngestEbaySoldComps $ingest,
    ) {}

    /**
     * @return array{due: int, processed: int, ingested: int, capped: bool}
     */
    public function __invoke(int $limit, int $minValueCents, int $maxValueCents, int $maxAgeHours, bool $dryRun = false): array
    {
        $due = $this->dueProducts($limit, $minValueCents, $maxValueCents, $maxAgeHours);

        $processed = 0;
        $ingested = 0;
        $capped = false;

        foreach ($due as $item) {
            if (! $dryRun && ! $this->underCap()) {
                $capped = true;
                break;
            }

            if ($dryRun) {
                $processed++;

                continue;
            }

            $this->spendCap();

            try {
                $ingested += ($this->ingest)($item);
            } catch (Throwable $e) {
                report($e);
            }

            $processed++;
        }

        return ['due' => $due->count(), 'processed' => $processed, 'ingested' => $ingested, 'capped' => $capped];
    }

    /**
     * Sealed products worth a paid pull: value within [min, max] (the max skips
     * data-error / ultra-thin outliers — e.g. mispriced "Booster Box Cases" with
     * no real eBay market), not refreshed within the window, most valuable first.
     *
     * @return Collection<int, CatalogItem>
     */
    private function dueProducts(int $limit, int $minValueCents, int $maxValueCents, int $maxAgeHours): Collection
    {
        $cutoff = CarbonImmutable::now()->subHours($maxAgeHours);

        return CatalogItem::query()
            ->where('item_type', ItemType::Sealed->value)
            // Skip Cases / Displays — thin-market, and "case" is too ambiguous in
            // listing titles ("fresh case", "in acrylic case") to match reliably,
            // so a 12-box Case pulls single-box sales. The synthetic seed stands.
            ->where('name', 'not like', '%Case%')
            ->where('name', 'not like', '%Display%')
            ->where(fn (Builder $q) => $q
                ->whereNull('ebay_refreshed_at')
                ->orWhere('ebay_refreshed_at', '<', $cutoff))
            ->whereHas('marketValues', fn (Builder $q) => $q
                ->whereIn('state_key', ['NM', 'SEALED'])
                ->where('median', '>=', $minValueCents)
                ->where('median', '<=', $maxValueCents))
            ->withMax(
                ['marketValues as sweep_value' => fn (Builder $q) => $q->whereIn('state_key', ['NM', 'SEALED'])],
                'median',
            )
            ->orderByDesc('sweep_value')
            ->limit($limit)
            ->get();
    }

    private function dailyKey(): string
    {
        return 'ebay:daily:'.Carbon::now()->toDateString();
    }

    private function underCap(): bool
    {
        Cache::add($this->dailyKey(), 0, Carbon::now()->endOfDay());

        return (int) Cache::get($this->dailyKey(), 0) < (int) config('valuation.ebay.daily_cap');
    }

    private function spendCap(): void
    {
        Cache::add($this->dailyKey(), 0, Carbon::now()->endOfDay());
        Cache::increment($this->dailyKey());
    }
}
