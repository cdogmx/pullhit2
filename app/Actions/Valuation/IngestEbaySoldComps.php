<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Support\Ebay\EbayBlockedException;
use App\Support\Ebay\EbaySoldSource;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldComp;
use App\Support\Ebay\SoldCompClassifier;
use App\Support\Valuation\RawAnchor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Pull real eBay sold comps for a catalog item, filter/classify them, ingest the
 * survivors as sale_observations, replace the card's synthetic placeholders, and
 * recompute its market values. The eBay SourceAdapter for the Phase-3 engine.
 *
 * Fetching and ingesting are separate because there are now two ways to get the
 * page. The server fetches through Oxylabs; when eBay gates that (it requires a
 * signed-in session for completed listings as of September 2026) the same HTML
 * arrives instead from the browser agent, already fetched. Everything after the
 * bytes — classify, store, drop synthetics, recompute — has to be identical
 * whichever way they came, so it lives in {@see ingest()} and both callers use it.
 */
class IngestEbaySoldComps
{
    /**
     * Raw candidates needed before their median is worth trusting as a seed.
     *
     * Four sales can sit anywhere; the middle of them says little. This is a
     * judgement, not a measurement — it is set where a median stops being an
     * accident and is deliberately low, because the cost of being wrong here is
     * only that a card waits one refresh for its first sanity check.
     */
    private const MIN_SEED = 5;

    public function __construct(
        protected EbaySoldSource $source,
        protected SoldCompClassifier $classifier,
        protected RecomputeCatalogItem $recompute,
        protected RawAnchor $anchor,
    ) {}

    /** @return int  number of accepted comps ingested */
    public function __invoke(CatalogItem $item): int
    {
        try {
            $candidates = $this->source->fetch($item);
        } catch (EbayBlockedException) {
            // Anti-bot interstitial / captcha — NOT a genuine empty result. Leave
            // ebay_refreshed_at untouched so the next view retries, rather than
            // holding a false "no comps" for the freshness window.
            Log::info('eBay fetch blocked; will retry on next view.', ['item' => $item->id]);

            return 0;
        }

        return $this->ingest($item, $candidates);
    }

    /**
     * Classify and store candidates that have already been fetched, from wherever.
     *
     * @param  array<int, SoldCandidate>  $candidates
     * @return int number of accepted comps ingested
     */
    public function ingest(CatalogItem $item, array $candidates): int
    {
        $companyIds = GradingCompany::pluck('id', 'slug')->all();
        $anchor = $this->anchor->for($item);

        // Nothing to judge against means the band waves everything through, and
        // the first wrong listing becomes the median that judges the rest. The
        // batch itself is something: every candidate here is for this one card,
        // so its own raw median is a seed — not authoritative, but enough to
        // notice the listing that is twenty times the others.
        if ($anchor <= 0) {
            $anchor = $this->seedAnchor($candidates, $companyIds);
        }

        $accepted = array_values(array_filter(array_map(
            fn ($candidate) => $this->classifier->classify($candidate, $item, $anchor, $companyIds),
            $candidates,
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

    /**
     * A first anchor taken from the candidates themselves, or 0 to stay open.
     *
     * Raw candidates only: a PSA 10 sells for many times raw, so seeding from
     * everything would lift the band and let the expensive raw outlier back in.
     *
     * Below MIN_SEED there is no meaningful middle, and refusing the batch would
     * stop a quiet card ever getting a first price — so it stays permissive and
     * the card is judged on its next refresh instead.
     *
     * @param  array<int, SoldCandidate>  $candidates
     * @param  array<string, int>  $companyIds
     */
    protected function seedAnchor(array $candidates, array $companyIds): int
    {
        $raw = [];

        foreach ($candidates as $candidate) {
            if ($this->classifier->pricedState($candidate, $companyIds)->gradingCompanyId === null) {
                $raw[] = $candidate->priceCents;
            }
        }

        if (count($raw) < self::MIN_SEED) {
            return 0;
        }

        sort($raw);

        return (int) $raw[intdiv(count($raw), 2)];
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
                'seller' => $comp->seller,
                'is_outlier' => false,
                'is_synthetic' => false,
                'raw' => ['title' => $comp->title, 'url' => $comp->url, 'seller' => $comp->seller, 'source' => 'ebay'],
            ],
        );
    }

}
