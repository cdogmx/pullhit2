<?php

namespace App\Support\Valuation;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cards whose raw price disagrees with an outside reference.
 *
 * Shared by the health report and the AI adjudicator so there is one definition
 * of "suspect" rather than two that drift. It is also the selector that makes AI
 * affordable here: the deterministic classifier is right on about 99.8% of
 * comps, so paying a model to re-read all of them buys almost nothing. Spending
 * it on the few hundred cards where our price provably disagrees with
 * PriceCharting puts it exactly where the rules are known to fail.
 *
 * Ratios run ours / theirs, so 12.5 means we say a $4 card is worth $53.
 */
class PriceDivergence
{
    public function __construct(private RawAnchor $anchor) {}

    /**
     * @param  float  $ratio  flag at or beyond this multiple, in either direction
     * @param  int  $minCents  ignore cards cheaper than this, where ratios are noise
     * @return Collection<int, array{item: CatalogItem, ours: int, theirs: int, ratio: float}>
     */
    public function cards(float $ratio = 3.0, int $minCents = 300, ?string $setSlug = null): Collection
    {
        $out = collect();

        CatalogItem::query()
            ->whereNotNull('set_id')
            ->whereNotNull('number')
            ->when($setSlug, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            // By set, so the anchor's per-set PriceCharting cache stays warm.
            ->orderBy('set_id')
            ->with('set:id,name')
            ->chunk(500, function ($items) use ($ratio, $minCents, &$out) {
                $ours = MarketValue::whereIn('catalog_item_id', $items->pluck('id'))
                    ->whereNull('grading_company_id')
                    ->where('state_key', 'NM')
                    // An estimate is our model talking to itself; comparing it
                    // against PriceCharting measures the model, not the data.
                    ->where('is_estimated', false)
                    ->pluck('median', 'catalog_item_id');

                foreach ($items as $item) {
                    $mine = (int) ($ours[$item->id] ?? 0);

                    if ($mine < $minCents) {
                        continue;
                    }

                    $theirs = $this->anchor->reference($item);

                    if ($theirs === null || $theirs < $minCents) {
                        continue;
                    }

                    $r = $mine / $theirs;

                    if ($r >= $ratio || $r <= 1 / $ratio) {
                        $out->push(['item' => $item, 'ours' => $mine, 'theirs' => $theirs, 'ratio' => $r]);
                    }
                }
            });

        return $out->sortByDesc(fn ($r) => max($r['ratio'], 1 / $r['ratio']))->values();
    }

    /**
     * Every compared card bucketed by how far apart the two prices are.
     *
     * @return array{compared: int, buckets: array<string, int>}
     */
    public function distribution(int $minCents = 300, ?string $setSlug = null): array
    {
        $buckets = ['under 0.2x' => 0, '0.2–0.5x' => 0, '0.5–2x' => 0, '2–5x' => 0, '5–10x' => 0, 'over 10x' => 0];
        $compared = 0;

        CatalogItem::query()
            ->whereNotNull('set_id')
            ->whereNotNull('number')
            ->when($setSlug, fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            ->orderBy('set_id')
            ->chunk(500, function ($items) use ($minCents, &$buckets, &$compared) {
                $ours = MarketValue::whereIn('catalog_item_id', $items->pluck('id'))
                    ->whereNull('grading_company_id')
                    ->where('state_key', 'NM')
                    ->where('is_estimated', false)
                    ->pluck('median', 'catalog_item_id');

                foreach ($items as $item) {
                    $mine = (int) ($ours[$item->id] ?? 0);

                    if ($mine < $minCents) {
                        continue;
                    }

                    $theirs = $this->anchor->reference($item);

                    if ($theirs === null || $theirs < $minCents) {
                        continue;
                    }

                    $compared++;
                    $r = $mine / $theirs;

                    $buckets[match (true) {
                        $r < 0.2 => 'under 0.2x',
                        $r < 0.5 => '0.2–0.5x',
                        $r < 2 => '0.5–2x',
                        $r < 5 => '2–5x',
                        $r < 10 => '5–10x',
                        default => 'over 10x',
                    }]++;
                }
            });

        return ['compared' => $compared, 'buckets' => $buckets];
    }
}
