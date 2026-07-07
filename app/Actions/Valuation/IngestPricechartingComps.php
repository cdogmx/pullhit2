<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldComp;
use App\Support\Ebay\SoldCompClassifier;
use App\Support\Pricecharting\CompletedSale;
use App\Support\Pricecharting\PricechartingSoldSource;
use Illuminate\Support\Carbon;

/**
 * Pull a catalog item's completed sales from PriceCharting (eBay + TCGplayer,
 * with real dates going back further than eBay's own sold view), run them through
 * the SAME sold-comp classifier as the eBay path, ingest survivors as
 * sale_observations tagged with each sale's source venue, and recompute the
 * item's values. Blends with eBay comps — dedupes on (source_listing_id, venue).
 */
class IngestPricechartingComps
{
    public function __construct(
        protected PricechartingSoldSource $source,
        protected SoldCompClassifier $classifier,
        protected RecomputeCatalogItem $recompute,
    ) {}

    /** @return int  number of accepted comps ingested */
    public function __invoke(CatalogItem $item): int
    {
        $anchor = $this->anchorCents($item);
        $companyIds = GradingCompany::pluck('id', 'slug')->all();
        $data = $this->source->fetchData($item);

        // Long-term monthly series (older than eBay's sold view) + a sync marker,
        // stored whether or not any comps pass — it drives the on-view TTL too.
        $item->forceFill([
            'pc_price_history' => $data->history !== [] ? $data->history : null,
            'pc_synced_at' => Carbon::now(),
        ])->save();

        $accepted = [];
        foreach ($data->comps as $sale) {
            $comp = $this->classifier->classify($this->toCandidate($sale), $item, $anchor, $companyIds);

            if ($comp !== null) {
                $accepted[] = [$sale->source->value, $comp];
            }
        }

        if ($accepted === []) {
            return 0; // keep whatever we already have (synthetic or eBay)
        }

        // Real data wins over synthetic placeholders (as the eBay path does).
        $item->saleObservations()->where('is_synthetic', true)->delete();

        foreach ($accepted as [$venue, $comp]) {
            $this->store($item, $venue, $comp);
        }

        ($this->recompute)($item);

        return count($accepted);
    }

    private function toCandidate(CompletedSale $sale): SoldCandidate
    {
        return new SoldCandidate(
            title: $sale->title,
            priceCents: $sale->priceCents,
            soldAt: $sale->soldAt,
            itemId: $sale->listingId,
            url: $sale->url,
        );
    }

    protected function store(CatalogItem $item, string $venue, SoldComp $comp): void
    {
        $item->saleObservations()->updateOrCreate(
            ['source_listing_id' => $comp->sourceListingId, 'venue' => $venue],
            [
                'condition' => $comp->condition,
                'grading_company_id' => $comp->gradingCompanyId,
                'grade' => $comp->grade,
                'grade_label' => $comp->gradeLabel,
                'price' => $comp->priceCents,
                'currency' => 'USD',
                'observed_at' => $comp->soldAt ?? Carbon::now(),
                'seller' => $comp->seller,
                'is_outlier' => false,
                'is_synthetic' => false,
                'raw' => ['title' => $comp->title, 'url' => $comp->url, 'source' => 'pricecharting:'.$venue],
            ],
        );
    }

    protected function anchorCents(CatalogItem $item): int
    {
        return (int) ($item->marketValues()
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->value('median') ?? 0);
    }
}
