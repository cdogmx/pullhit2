<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\Subsets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turn a race's sources into the cards it should actually race.
 *
 * Sources are a spec, not a list, so "the 30th Celebration" keeps meaning that
 * set as cards are added to it. Four were added by hand the day this was
 * written, and a race saved the day before should pick them up.
 *
 * The cap is the load-bearing part. A set is 140 cards and a series is 1,171,
 * but a brand is 19,263 across 1,791 days of sales — and the race walks every
 * card against every day, so an uncapped brand race is thirty-four million
 * window computations for a chart that shows thirty bars. Candidates are ranked
 * by what they are worth now and the tail is dropped.
 *
 * That cap has a known blind spot, and it is the honest cost of the feature: a
 * card that spiked in 2019 and is cheap today never enters the race, even
 * though its spike is exactly the moment worth watching. Ranking by peak value
 * would catch it and needs a pass over value_snapshots that a page load cannot
 * afford. Set and series races are under the cap entirely and are unaffected.
 */
class ResolveRaceSources
{
    /** Cards a race may consider before the tail is cut by current value. */
    public const CANDIDATE_CAP = 250;

    /** Sales a card needs before it is worth racing at all. */
    private const MIN_LIQUIDITY = 5;

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array{ids: array<int, int>, capped: bool, considered: int}
     */
    public function __invoke(array $sources): array
    {
        $ids = collect();

        foreach ($sources as $source) {
            $ids = $ids->merge(match ($source['type'] ?? null) {
                'set' => $this->fromSet((string) ($source['slug'] ?? '')),
                'series' => $this->fromSeries((string) ($source['name'] ?? ''), $source['line'] ?? null),
                'brand' => $this->fromBrand((string) ($source['slug'] ?? '')),
                'cards' => $this->fromCards($source['ids'] ?? []),
                default => collect(),
            });
        }

        $ids = $ids->unique()->values();
        $considered = $ids->count();

        if ($considered <= self::CANDIDATE_CAP) {
            return ['ids' => $ids->all(), 'capped' => false, 'considered' => $considered];
        }

        return [
            'ids' => $this->mostValuable($ids),
            'capped' => true,
            'considered' => $considered,
        ];
    }

    /** A set and the subsets that hang off it, the way browse and the home page count them. */
    private function fromSet(string $slug): Collection
    {
        $set = Set::where('slug', $slug)->first();

        if (! $set) {
            return collect();
        }

        $family = Set::query()
            ->where('product_line_id', $set->product_line_id)
            ->where('language', $set->language)
            ->where(fn (Builder $q) => $q
                ->whereKey($set->getKey())
                ->orWhereIn('name', array_map(
                    fn (string $suffix) => $set->name.' '.$suffix,
                    Subsets::SUFFIXES,
                )))
            ->pluck('id');

        return $this->singles(CatalogItem::whereIn('set_id', $family));
    }

    private function fromSeries(string $name, ?string $line): Collection
    {
        if ($name === '') {
            return collect();
        }

        $sets = Set::where('series', $name)
            ->when($line, fn (Builder $q, $slug) => $q->whereHas(
                'productLine', fn (Builder $p) => $p->where('slug', $slug),
            ))
            ->pluck('id');

        return $this->singles(CatalogItem::whereIn('set_id', $sets));
    }

    private function fromBrand(string $slug): Collection
    {
        $line = ProductLine::where('slug', $slug)->first();

        return $line
            ? $this->singles(CatalogItem::where('product_line_id', $line->id))
            : collect();
    }

    /**
     * A hand-picked list, checked against the catalog.
     *
     * Ids arrive from a saved race that may be months old, and a card can be
     * merged away or deleted between saving and watching. Passing them straight
     * through would race ghosts, and a race of nothing but ghosts would save
     * itself as a blank chart with a name on it.
     *
     * @param  array<int, mixed>  $ids
     */
    private function fromCards(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return $this->singles(
            CatalogItem::whereIn('id', array_map('intval', $ids)),
        );
    }

    /**
     * Cards, not boxes. A sealed product's price moves for its own reasons and
     * dwarfs the singles inside it, so one booster box would simply sit at the
     * top of the race for its whole length.
     *
     * @param  Builder<CatalogItem>  $query
     */
    private function singles(Builder $query): Collection
    {
        return $query->where('item_type', ItemType::Single)->pluck('id');
    }

    /**
     * The most valuable candidates that actually trade.
     *
     * Value alone is the wrong rank for a race. Sorting a brand by price picks
     * the vintage grails — the most expensive cards in the catalog are also the
     * least liquid, selling a handful of times a year — and a race between them
     * is an empty chart: fewer than five had a sale in the same week, so the
     * field never formed and a brand race came back with a single frame.
     *
     * Requiring a card to have traded fixes it. What is left is expensive AND
     * moving, which is what a race is made of.
     *
     * @return array<int, int>
     */
    private function mostValuable(Collection $ids): array
    {
        return MarketValue::query()
            ->whereIn('catalog_item_id', $ids)
            ->whereNull('grading_company_id')
            ->where('is_estimated', false)
            ->where('median', '>', 0)
            ->where('n_sales', '>=', self::MIN_LIQUIDITY)
            ->orderByDesc('median')
            ->limit(self::CANDIDATE_CAP)
            ->pluck('catalog_item_id')
            ->unique()
            ->values()
            ->all();
    }
}
