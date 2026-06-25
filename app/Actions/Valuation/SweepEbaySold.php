<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\EbaySweepMiss;
use App\Models\EbaySweepOverride;
use App\Models\GradingCompany;
use App\Support\Ebay\EbayHtmlParser;
use App\Support\Ebay\EbayTitleResolver;
use App\Support\Ebay\OxylabsClient;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldComp;
use App\Support\Ebay\SoldCompClassifier;
use Illuminate\Support\Carbon;

/**
 * One broad eBay sold-listing sweep: fetch a search URL via Oxylabs, parse the
 * sold listings, resolve each title back to a catalog card, validate it with the
 * same comp classifier the per-card pull uses, and ingest the survivors as
 * sale_observations — then recompute the affected cards' values. Listings we
 * can't confidently place are logged to ebay_sweep_misses for tuning, never
 * applied. Shares the daily Oxylabs cap with the per-card path.
 */
class SweepEbaySold
{
    public function __construct(
        protected OxylabsClient $client,
        protected EbayTitleResolver $resolver,
        protected SoldCompClassifier $classifier,
        protected RecomputeCatalogItem $recompute,
    ) {}

    /**
     * @param  array{label:string, url:string, language?:string, interval_minutes?:int}  $search
     * @return array{label:string, fetched:int, matched:int, stored:int, missed:int, recomputed:int}
     */
    public function __invoke(array $search, bool $dryRun = false): array
    {
        $label = $search['label'];
        $language = $search['language'] ?? null;
        $minScore = (float) config('valuation.ebay.sweep.min_score', 0.75);

        $candidates = EbayHtmlParser::parse(
            $this->client->fetchHtml($search['url'], config('valuation.ebay.geo', 'United States')),
        );

        $companyIds = GradingCompany::pluck('id', 'slug')->all();

        // Sticky admin decisions for the listings on this page (reject / reassign).
        $listingIds = array_values(array_filter(array_map(fn ($c) => $c->itemId, $candidates)));
        $overrides = EbaySweepOverride::whereIn('source_listing_id', $listingIds)->get()->keyBy('source_listing_id');

        /** @var array<int, CatalogItem> $affected */
        $affected = [];
        $matched = 0;
        $stored = 0;
        $missed = 0;

        foreach ($candidates as $candidate) {
            // An admin decision wins over the resolver, and holds across re-pulls.
            $override = $candidate->itemId ? $overrides->get($candidate->itemId) : null;
            if ($override) {
                if ($override->action === EbaySweepOverride::REJECT) {
                    continue;
                }

                $forced = $override->catalog_item_id ? CatalogItem::find($override->catalog_item_id) : null;
                if ($forced && ! $dryRun) {
                    $this->store($forced, $this->classifier->pricedState($candidate, $companyIds), $label);
                    $stored++;
                    $matched++;
                    $affected[$forced->id] = $forced;
                }

                continue;
            }

            $resolution = $this->resolver->resolve($candidate->title, $language, $minScore);
            $item = $resolution['item'];

            if (! $item) {
                $missed++;
                if (! $dryRun) {
                    $this->logMiss($label, $candidate, $resolution['reason'], $resolution['number'], $resolution['best_id'], $resolution['score']);
                }

                continue;
            }

            // Final guardrail: the same classifier the per-card pull trusts —
            // confirms it's a single-card sale of THIS printing and reads grade.
            $comp = $this->classifier->classify($candidate, $item, $this->anchorCents($item), $companyIds);

            if (! $comp) {
                $missed++;
                if (! $dryRun) {
                    $this->logMiss($label, $candidate, 'classify_rejected', $resolution['number'], $item->id, $resolution['score']);
                }

                continue;
            }

            $matched++;

            if ($dryRun) {
                continue;
            }

            $this->store($item, $comp, $label);
            $stored++;
            $affected[$item->id] = $item;
        }

        foreach ($affected as $item) {
            ($this->recompute)($item);
        }

        return [
            'label' => $label,
            'fetched' => count($candidates),
            'matched' => $matched,
            'stored' => $stored,
            'missed' => $missed,
            'recomputed' => count($affected),
        ];
    }

    protected function store(CatalogItem $item, SoldComp $comp, string $label): void
    {
        // Real data wins: clear the synthetic placeholder for THIS priced state
        // only (a graded sweep must not blank the card's raw value).
        $synthetic = $item->saleObservations()->where('is_synthetic', true);
        $comp->gradingCompanyId === null
            ? $synthetic->whereNull('grading_company_id')
            : $synthetic->where('grading_company_id', $comp->gradingCompanyId);
        $comp->grade === null ? $synthetic->whereNull('grade') : $synthetic->where('grade', $comp->grade);
        $comp->condition === null ? $synthetic->whereNull('condition') : $synthetic->where('condition', $comp->condition);
        $synthetic->delete();

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
                'seller' => $comp->seller,
                'is_outlier' => false,
                'is_synthetic' => false,
                'raw' => ['title' => $comp->title, 'url' => $comp->url, 'image' => $comp->imageUrl, 'seller' => $comp->seller, 'source' => 'ebay_sweep', 'sweep' => $label],
            ],
        );
    }

    protected function logMiss(string $label, SoldCandidate $candidate, string $reason, ?string $number, ?int $bestId, float $score): void
    {
        if ($candidate->itemId === null) {
            return;
        }

        EbaySweepMiss::updateOrCreate(
            ['source_listing_id' => $candidate->itemId],
            [
                'search_label' => $label,
                'title' => mb_substr($candidate->title, 0, 255),
                'image_url' => $candidate->imageUrl,
                'price' => $candidate->priceCents,
                'sold_at' => $candidate->soldAt,
                'parsed_number' => $number,
                'reason' => $reason,
                'best_catalog_item_id' => $bestId,
                'best_score' => $score,
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
