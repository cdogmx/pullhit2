<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Ebay\OxylabsClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
        protected OxylabsClient $oxylabs,
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
            // Advisory: OxylabsClient bills and enforces per delivered result.
            if (! $dryRun && ! $this->oxylabs->hasBudget(OxylabsClient::BUDGET_EBAY)) {
                $capped = true;
                break;
            }

            if ($dryRun) {
                $processed++;

                continue;
            }

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
}
