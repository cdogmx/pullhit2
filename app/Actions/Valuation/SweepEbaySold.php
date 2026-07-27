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
use Carbon\CarbonImmutable;
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

    /** Card ids awaiting a value recompute while deferral is on. */
    protected array $deferred = [];

    protected bool $deferRecompute = false;

    /** @var ?array<string, EbaySweepOverride> preloaded overrides, or null to query per row */
    protected ?array $overrides = null;

    /** @var array<int, int> card id => anchor cents, only while deferring */
    protected array $anchorCache = [];

    /**
     * @param  array{label:string, url:string, language?:string, interval_minutes?:int}  $search
     * @return array{label:string, fetched:int, matched:int, stored:int, missed:int, recomputed:int}
     */
    public function __invoke(array $search, bool $dryRun = false): array
    {
        $label = $search['label'];
        $language = $search['language'] ?? null;
        $line = $search['line'] ?? null;
        $minScore = (float) config('valuation.ebay.sweep.min_score', 0.75);

        $candidates = EbayHtmlParser::parse(
            $this->client->fetchHtml(
                $search['url'],
                config('valuation.ebay.geo', 'United States'),
                budget: OxylabsClient::BUDGET_EBAY,
            ),
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

            $resolution = $this->resolver->resolve($candidate->title, $language, $minScore, $line);
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
            [$item, $comp] = $this->classifyVariant($candidate, $resolution, $companyIds);

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

    /**
     * Batch mode for backlog replays (see ResweepMissesCommand).
     *
     * Recomputing per applied miss is the wrong unit of work when thousands of
     * sales land on the same few thousand cards — the value only needs deriving
     * once all of them are stored. Deferring collects the ids instead; the
     * caller flushes at the end. The only cost is that a later miss for the same
     * card judges its price band against a slightly stale anchor, which is true
     * of a live sweep anyway.
     */
    public function deferRecomputes(bool $defer = true): void
    {
        $this->deferRecompute = $defer;
    }

    /**
     * Recompute every card touched since deferral began, once each.
     *
     * @param  ?callable(int, int): void  $progress  called with (done, total)
     * @return int cards recomputed
     */
    public function flushRecomputes(?callable $progress = null): int
    {
        $ids = array_keys($this->deferred);
        $this->deferred = [];
        $this->anchorCache = []; // values are about to change; drop the memo
        $done = 0;
        $total = count($ids);

        foreach (array_chunk($ids, 200) as $chunk) {
            foreach (CatalogItem::whereIn('id', $chunk)->get() as $item) {
                ($this->recompute)($item);
                $done++;
                $progress && $progress($done, $total);
            }
        }

        return $done;
    }

    /** Cardinality of the pending recompute set (cards, not sales). */
    public function pendingRecomputes(): int
    {
        return count($this->deferred);
    }

    /**
     * Load every admin override up front. A replay looks one up per miss, and
     * the table is tiny (hundreds) against a corpus of tens of thousands — so
     * one query replaces one per row.
     */
    public function primeOverrides(): void
    {
        $this->overrides = EbaySweepOverride::all()->keyBy('source_listing_id')->all();
    }

    protected function overrideFor(?string $listingId): ?EbaySweepOverride
    {
        if ($listingId === null) {
            return null;
        }

        if ($this->overrides !== null) {
            return $this->overrides[$listingId] ?? null;
        }

        return EbaySweepOverride::where('source_listing_id', $listingId)->first();
    }

    protected function recomputeOrDefer(CatalogItem $item): void
    {
        if ($this->deferRecompute) {
            $this->deferred[$item->id] = true;

            return;
        }

        ($this->recompute)($item);
    }

    /**
     * Classify a candidate against the resolved card, falling back through that
     * card's other printings. The resolver ranks on name/number/set, which can't
     * separate a reverse holo from a regular one — so its top pick is often the
     * wrong printing and the classifier's printing gate then kills the sale
     * outright. Trying the siblings turns that rejection into the right comp;
     * "wrong printing / variant" was 70% of all classifier rejections.
     *
     * @param  array{item: ?CatalogItem, variants: array<int, CatalogItem>}  $resolution
     * @param  array<string, int>  $companyIds
     * @return array{0: CatalogItem, 1: ?SoldComp}
     */
    protected function classifyVariant(SoldCandidate $candidate, array $resolution, array $companyIds): array
    {
        $best = $resolution['item'];
        $variants = $resolution['variants'] ?: [$best];

        foreach ($variants as $variant) {
            $comp = $this->classifier->classify($candidate, $variant, $this->anchorCents($variant), $companyIds);

            if ($comp) {
                return [$variant, $comp];
            }
        }

        // Nothing fit — report against the resolver's pick, so the logged miss
        // still points at the most likely card for review.
        return [$best, null];
    }

    protected function anchorCents(CatalogItem $item): int
    {
        // Memoized while recomputes are deferred. In that mode values aren't
        // being rewritten mid-run, so a card's anchor is fixed for the duration
        // — and a backlog replay asks the same few thousand cards for it over
        // and over, once per printing tried.
        if ($this->deferRecompute && isset($this->anchorCache[$item->id])) {
            return $this->anchorCache[$item->id];
        }

        $anchor = (int) ($item->marketValues()
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->value('median') ?? 0);

        if ($this->deferRecompute) {
            $this->anchorCache[$item->id] = $anchor;
        }

        return $anchor;
    }

    /**
     * Re-evaluate one already-logged miss against the CURRENT resolver +
     * classifier (e.g. after improving number parsing) — no network, the title,
     * price and date come from the stored row. When it now matches, ingest the
     * sale, recompute the card and clear the miss; otherwise refresh the miss's
     * reason / best-guess in place so the admin sees the improved verdict.
     *
     * @param  array<string, int>  $companyIds  grading company slug => id
     * @return string applied | reclassified | rematched | unchanged | skipped
     */
    public function reprocessMiss(
        EbaySweepMiss $miss,
        ?string $language,
        float $minScore,
        array $companyIds,
        bool $apply = true,
        ?string $productLine = null,
    ): string {
        if ($miss->source_listing_id === null || (int) $miss->price <= 0) {
            return 'skipped';
        }

        $candidate = $this->missCandidate($miss);

        // A sticky admin decision still wins.
        $override = $this->overrideFor($miss->source_listing_id);
        if ($override?->action === EbaySweepOverride::REJECT) {
            return 'skipped';
        }
        $forced = $override && $override->catalog_item_id ? CatalogItem::find($override->catalog_item_id) : null;
        if ($forced) {
            if ($apply) {
                $this->store($forced, $this->classifier->pricedState($candidate, $companyIds), $miss->search_label);
                $this->recomputeOrDefer($forced);
                $miss->delete();
            }

            return 'applied';
        }

        $resolution = $this->resolver->resolve($miss->title, $language, $minScore, $productLine);
        $item = $resolution['item'];

        if ($item) {
            [$item, $comp] = $this->classifyVariant($candidate, $resolution, $companyIds);
            if ($comp) {
                if ($apply) {
                    $this->store($item, $comp, $miss->search_label);
                    $this->recomputeOrDefer($item);
                    $miss->delete();
                }

                return 'applied';
            }

            // Resolves to a card now, but the classifier still rejects it.
            if ($apply) {
                $this->refreshMiss($miss, 'classify_rejected', $resolution['number'], $item->id, $resolution['score']);
            }

            return 'reclassified';
        }

        // Still unmatched, but the reason / best-guess may have improved.
        $changed = $miss->reason !== $resolution['reason']
            || $miss->parsed_number !== $resolution['number']
            || $miss->best_catalog_item_id !== $resolution['best_id'];

        if ($apply && $changed) {
            $this->refreshMiss($miss, $resolution['reason'], $resolution['number'], $resolution['best_id'], $resolution['score']);
        }

        return $changed ? 'rematched' : 'unchanged';
    }

    /**
     * Apply an already-logged miss to a chosen card, running the SAME reject
     * gates as the live sweep (blocklist, multi-quantity, multi-card bundle,
     * name, printing, price band) so a machine match can never ingest a set/lot
     * listing as a single-card comp. Returns false (ingesting nothing) when the
     * listing fails a gate; on success it pins the listing, recomputes and clears
     * the miss. Grade/condition come from the shared classifier.
     */
    public function applyMissToCard(EbaySweepMiss $miss, CatalogItem $card, string $sweepTag = 'manual'): bool
    {
        $comp = $this->classifier->classify(
            $this->missCandidate($miss),
            $card,
            $this->anchorCents($card),
            GradingCompany::pluck('id', 'slug')->all(),
        );

        if (! $comp) {
            return false;
        }

        $this->store($card, $comp, $sweepTag);

        if ($miss->source_listing_id) {
            EbaySweepOverride::updateOrCreate(
                ['source_listing_id' => $miss->source_listing_id],
                ['action' => EbaySweepOverride::REASSIGN, 'catalog_item_id' => $card->id, 'title' => $miss->title, 'created_by' => auth()->id()],
            );
        }

        ($this->recompute)($card);
        $miss->delete();

        return true;
    }

    /** Record a best-guess card on a miss for admin review (no ingest). */
    public function suggestMissCard(EbaySweepMiss $miss, CatalogItem $card, float $score): void
    {
        $miss->update(['best_catalog_item_id' => $card->id, 'best_score' => round($score, 2)]);
    }

    private function missCandidate(EbaySweepMiss $miss): SoldCandidate
    {
        return new SoldCandidate(
            $miss->title,
            (int) $miss->price,
            $miss->sold_at ? CarbonImmutable::parse($miss->sold_at) : null,
            $miss->source_listing_id,
            "https://www.ebay.com/itm/{$miss->source_listing_id}",
            null,
            $miss->image_url,
        );
    }

    private function refreshMiss(EbaySweepMiss $miss, string $reason, ?string $number, ?int $bestId, float $score): void
    {
        $miss->update([
            'reason' => $reason,
            'parsed_number' => $number,
            'best_catalog_item_id' => $bestId,
            'best_score' => $score,
        ]);
    }
}
