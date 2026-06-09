<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\SoldComp;
use App\Support\Ebay\SoldCompClassifier;
use Illuminate\Support\Carbon;

/**
 * Pull real eBay sold comps for a catalog item, filter/classify them, ingest the
 * survivors as sale_observations, replace the card's synthetic placeholders, and
 * recompute its market values. The eBay SourceAdapter for the Phase-3 engine.
 */
class IngestEbaySoldComps
{
    public function __construct(
        protected EbaySoldSource $source,
        protected SoldCompClassifier $classifier,
        protected RecomputeCatalogItem $recompute,
    ) {}

    /** @return int  number of accepted comps ingested */
    public function __invoke(CatalogItem $item): int
    {
        $anchor = $this->anchorCents($item);
        $companyIds = GradingCompany::pluck('id', 'slug')->all();

        $accepted = array_values(array_filter(array_map(
            fn ($candidate) => $this->classifier->classify($candidate, $item, $anchor, $companyIds),
            $this->source->fetch($item),
        )));

        // Always record the attempt so we respect the TTL even on a dry result.
        $item->forceFill(['ebay_refreshed_at' => Carbon::now()])->save();

        if ($accepted === []) {
            return 0; // keep the synthetic placeholder rather than blank the card
        }

        // Real data wins: drop this card's synthetic comps (replace-per-card).
        $item->saleObservations()->where('is_synthetic', true)->delete();

        foreach ($accepted as $comp) {
            $this->store($item, $comp);
        }

        ($this->recompute)($item);

        return count($accepted);
    }

    protected function store(CatalogItem $item, SoldComp $comp): void
    {
        $item->saleObservations()->updateOrCreate(
            ['source_listing_id' => $comp->sourceListingId, 'venue' => 'ebay'],
            [
                'condition' => $comp->condition,
                'grading_company_id' => $comp->gradingCompanyId,
                'grade' => $comp->grade,
                'grade_label' => $comp->gradeLabel,
                'price' => $comp->priceCents,
                'currency' => 'USD',
                'observed_at' => $comp->soldAt ?? Carbon::now(),
                'is_outlier' => false,
                'is_synthetic' => false,
                'raw' => ['title' => $comp->title, 'source' => 'ebay'],
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
