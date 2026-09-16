<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\Subsets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A set's first weeks as a running race: what each card was worth on each day,
 * and how the order changed.
 *
 * Built from sold comps rather than from value_snapshots, which keep one row per
 * card per day and only began for this set two days ago — the comps go back a
 * month. The price on a given day is a trailing median, not that day's sales:
 * the 19th of August saw eight sales across eight cards, and a median of one
 * sale is not a price, it is an anecdote. A window smooths that into something
 * that can be read without lying about how much is known.
 *
 * Sales volume rides along per day, because for a new set that curve is the
 * story — this one runs from eight sales a day in mid-August to twelve hundred.
 */
class BuildPriceRace
{
    /** Days of sales that inform one day's price. */
    private const WINDOW = 7;

    /** Sales needed inside the window before a card is worth plotting. */
    private const MIN_SALES = 2;

    /** Bars per frame. */
    private const BARS = 30;

    /** Bars needed before a day counts as part of the race. */
    private const MIN_FIELD = 5;

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(Set $set): ?array
    {
        $ids = $this->family($set);

        $sales = DB::table('sale_observations')
            ->whereIn('catalog_item_id', $ids)
            ->where('is_synthetic', false)
            ->whereNull('grading_company_id')
            ->where('price', '>', 0)
            ->selectRaw('catalog_item_id, date(observed_at) d, price')
            ->orderBy('d')
            ->get();

        if ($sales->isEmpty()) {
            return null;
        }

        $byCardDay = [];
        $volume = [];

        foreach ($sales as $row) {
            $byCardDay[$row->catalog_item_id][$row->d][] = (int) $row->price;
            $volume[$row->d] = ($volume[$row->d] ?? 0) + 1;
        }

        $days = $this->days(array_keys($volume));
        $frames = [];
        $roster = [];

        foreach ($days as $day) {
            $standing = [];

            foreach ($byCardDay as $cardId => $daily) {
                $prices = $this->window($daily, $day);

                if (count($prices) < self::MIN_SALES) {
                    continue;
                }

                $standing[] = ['id' => $cardId, 'value' => $this->median($prices), 'sales' => count($prices)];
            }

            usort($standing, fn ($a, $b) => $b['value'] <=> $a['value']);
            $standing = array_slice($standing, 0, self::BARS);

            foreach ($standing as $bar) {
                $roster[$bar['id']] = true;
            }

            $frames[] = [
                'day' => $day,
                'volume' => $volume[$day] ?? 0,
                'bars' => $standing,
            ];
        }

        $racing = $this->fromStableStart($frames);

        if ($racing === []) {
            return null;
        }

        return [
            'set' => ['name' => $set->name, 'slug' => $set->slug, 'released' => $set->released_at?->toDateString()],
            'cards' => $this->cards(array_keys($roster)),
            'frames' => $racing,
            // Every day, including the ones before the race starts. The ramp
            // from single sales in August to twelve hundred a day is the story
            // of the release, and it is finished before the race begins.
            'volume' => array_map(
                fn (array $f) => ['day' => $f['day'], 'sales' => $f['volume']],
                $frames,
            ),
            'window' => self::WINDOW,
        ];
    }

    /**
     * The race starts when there is a field to race.
     *
     * A set's first fortnight is a handful of presale sales: three bars, then
     * eight, then — across two days in late August with no sales at all — none,
     * which draws a chart that has gone blank rather than a market that is
     * quiet. Carrying the last price forward would fill those frames, but a
     * price no sale supports is exactly the invention the rest of this codebase
     * spends its time rejecting.
     *
     * So the tape begins at the first day from which the field never thins
     * again, and the weeks before it are told by the volume ribbon instead,
     * which needs no such minimum.
     *
     * @param  array<int, array<string, mixed>>  $frames
     * @return array<int, array<string, mixed>>
     */
    private function fromStableStart(array $frames): array
    {
        $start = null;

        foreach ($frames as $i => $frame) {
            if (count($frame['bars']) >= self::MIN_FIELD) {
                $start ??= $i;

                continue;
            }

            $start = null;   // thinned out again — that was not the start
        }

        return $start === null ? [] : array_values(array_slice($frames, $start));
    }

    /** The featured set plus its subsets, the way the home page counts them. */
    private function family(Set $set): array
    {
        $sets = Set::query()
            ->where('product_line_id', $set->product_line_id)
            ->where('language', $set->language)
            ->where(fn (Builder $q) => $q
                ->whereKey($set->getKey())
                ->orWhereIn('name', array_map(
                    fn (string $suffix) => $set->name.' '.$suffix,
                    Subsets::SUFFIXES,
                )))
            ->pluck('id');

        return CatalogItem::whereIn('set_id', $sets)->pluck('id')->all();
    }

    /**
     * Every day from the first sale to the last, including the quiet ones — a
     * gap in a race reads as time passing, and skipping it speeds the tape up
     * exactly where the market was slowest.
     *
     * @param  array<int, string>  $seen
     * @return array<int, string>
     */
    private function days(array $seen): array
    {
        sort($seen);
        $cursor = Carbon::parse($seen[0]);
        $last = Carbon::parse(end($seen));
        $days = [];

        while ($cursor->lte($last)) {
            $days[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * The prices inside the trailing window ending on $day.
     *
     * @param  array<string, array<int, int>>  $daily
     * @return array<int, int>
     */
    private function window(array $daily, string $day): array
    {
        $end = Carbon::parse($day);
        $start = $end->copy()->subDays(self::WINDOW - 1);
        $prices = [];

        foreach ($daily as $d => $dayPrices) {
            $at = Carbon::parse($d);

            if ($at->betweenIncluded($start, $end)) {
                $prices = array_merge($prices, $dayPrices);
            }
        }

        return $prices;
    }

    /** @param  array<int, int>  $prices */
    private function median(array $prices): int
    {
        sort($prices);
        $n = count($prices);
        $mid = intdiv($n, 2);

        return $n % 2 ? $prices[$mid] : (int) round(($prices[$mid - 1] + $prices[$mid]) / 2);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function cards(array $ids): array
    {
        return CatalogItem::whereIn('id', $ids)
            ->with(['set:id,name', 'productLine:id,slug'])
            ->get()
            ->map(fn (CatalogItem $i) => [
                'id' => $i->id,
                'name' => $i->display_name ?: $i->name,
                'number' => $i->number,
                'set' => $i->set?->name,
                'image' => $i->primary_image_path,
                'href' => $i->path(),
            ])
            ->keyBy('id')
            ->all();
    }
}
