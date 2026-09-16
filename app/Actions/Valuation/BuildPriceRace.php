<?php

namespace App\Actions\Valuation;

use App\Models\CatalogItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A run of cards as a race: what each was worth on each frame, and how the
 * order changed.
 *
 * Built from sold comps rather than from value_snapshots, which keep one row
 * per card per day and start whenever a card was first valued — the comps go
 * back to whenever it first sold. A frame's price is a trailing median, not
 * that frame's sales: the 19th of August saw eight sales across eight cards,
 * and the median of one sale is an anecdote wearing a price tag.
 *
 * Sales volume rides along per frame, because for a new set that curve is the
 * story — the 30th Celebration runs from eight sales a day in mid-August to
 * twelve hundred.
 */
class BuildPriceRace
{
    /** Days of sales that inform one frame's price. */
    public const WINDOW = 7;

    /** Sales needed inside the window before a card is worth plotting. */
    private const MIN_SALES = 2;

    /** Bars needed before a frame counts as part of the race. */
    private const MIN_FIELD = 5;

    /**
     * Frames a race may run to. Beyond this the step widens from days to weeks
     * to months: five years of daily frames is a twenty-seven minute tape, and
     * nobody watches a chart for twenty-seven minutes.
     */
    private const MAX_FRAMES = 120;

    /**
     * @param  array<int, int>  $cardIds
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|null
     */
    public function __invoke(array $cardIds, array $options = []): ?array
    {
        if ($cardIds === []) {
            return null;
        }

        $bars = max(3, min(50, (int) ($options['top'] ?? 30)));
        $window = max(1, min(30, (int) ($options['window'] ?? self::WINDOW)));

        $sales = DB::table('sale_observations')
            ->whereIn('catalog_item_id', $cardIds)
            ->where('is_synthetic', false)
            ->whereNull('grading_company_id')
            ->where('price', '>', 0)
            ->when($options['from'] ?? null, fn ($q, $from) => $q->whereDate('observed_at', '>=', $from))
            ->when($options['to'] ?? null, fn ($q, $to) => $q->whereDate('observed_at', '<=', $to))
            ->selectRaw('catalog_item_id, date(observed_at) d, price')
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

        $standings = $this->sweep($byCardDay, $days, $window);

        $frames = [];
        $roster = [];

        foreach ($days as $day) {
            $standing = $standings[$day] ?? [];

            usort($standing, fn ($a, $b) => $b['value'] <=> $a['value']);
            $standing = array_slice($standing, 0, $bars);

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

        // Step is decided after the trim, not before. A handful of stray old
        // sales can stretch the full range over months while the race itself
        // covers a fortnight, and stepping on the full range would coarsen the
        // part anyone is actually watching.
        $step = $this->step(count($racing));
        $racing = $this->downsample($racing, $step, $volume);

        return [
            'cards' => $this->cards(array_keys($roster)),
            'frames' => $racing,
            // Every day, including the ones before the race starts. The ramp
            // from single sales to hundreds a day is the story of a release, and
            // it is finished before any card has enough sales to be plotted.
            'volume' => array_map(
                fn (string $day) => ['day' => $day, 'sales' => $volume[$day] ?? 0],
                $days,
            ),
            'window' => $window,
            'step' => $step,
        ];
    }

    /**
     * Keep every Nth frame, and always the last one — a race that stops three
     * days short of today reads as stale rather than stepped. A kept frame
     * reports the sales of every day it now stands for.
     *
     * @param  array<int, array<string, mixed>>  $frames
     * @param  array<string, int>  $volume
     * @return array<int, array<string, mixed>>
     */
    private function downsample(array $frames, int $step, array $volume): array
    {
        if ($step <= 1) {
            return $frames;
        }

        $last = count($frames) - 1;
        $kept = [];

        foreach ($frames as $i => $frame) {
            if ($i % $step !== 0 && $i !== $last) {
                continue;
            }

            $frame['volume'] = $this->volumeOver($volume, $frame['day'], $step);
            $kept[] = $frame;
        }

        return $kept;
    }

    /**
     * Days per frame. A set's first month is daily; five years of a brand is
     * not, and widening the step is better than dropping the early history.
     */
    private function step(int $days): int
    {
        return max(1, (int) ceil($days / self::MAX_FRAMES));
    }

    /**
     * The race starts when there is a field to race.
     *
     * A set's first fortnight is a handful of presale sales: three bars, then
     * eight, then — across two days with no sales at all — none, which draws a
     * chart that has gone blank rather than a market that is quiet. Carrying the
     * last price forward would fill those frames, but a price no sale supports
     * is exactly the invention the rest of this codebase spends its time
     * rejecting. So the tape begins at the first frame from which the field
     * never thins again, and the time before it is told by the volume ribbon,
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

        // A race whose field never reaches the minimum is still a race if it
        // has bars at all; a hand-picked list of four cards is a legitimate one.
        if ($start === null) {
            $withBars = array_values(array_filter($frames, fn (array $f) => $f['bars'] !== []));

            return count($withBars) >= 2 ? $withBars : [];
        }

        return array_values(array_slice($frames, $start));
    }

    /**
     * Every day from the first sale to the last, including the quiet ones — a
     * gap reads as time passing, and skipping it speeds the tape up exactly
     * where the market was slowest.
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
     * Sales across the days this frame stands for, so a weekly frame reports its
     * whole week rather than one day of it.
     *
     * @param  array<string, int>  $volume
     */
    private function volumeOver(array $volume, string $day, int $step): int
    {
        $end = Carbon::parse($day);
        $total = 0;

        for ($i = 0; $i < $step; $i++) {
            $total += $volume[$end->copy()->subDays($i)->toDateString()] ?? 0;
        }

        return $total;
    }

    /**
     * Every card's trailing median on every day, in one pass per card.
     *
     * Asking each card for each day separately meant rescanning that card's
     * whole sales history once per day of the race — fine for a set over a
     * fortnight, twenty-six seconds for a brand over five years. Sweeping
     * forward and evicting what has aged out holds at most `window` days in
     * hand, so the cost stops depending on how long the card has been trading.
     *
     * @param  array<int, array<string, array<int, int>>>  $byCardDay
     * @param  array<int, string>  $days
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function sweep(array $byCardDay, array $days, int $window): array
    {
        $standings = [];

        foreach ($byCardDay as $cardId => $daily) {
            $held = [];   // day => prices, only those still inside the window

            foreach ($days as $i => $day) {
                if (isset($daily[$day])) {
                    $held[$day] = $daily[$day];
                }

                // Anything older than the window has aged out. At most `window`
                // entries are ever held, so this stays cheap.
                $oldest = $days[max(0, $i - $window + 1)];

                foreach (array_keys($held) as $d) {
                    if ($d < $oldest) {
                        unset($held[$d]);
                    }
                }

                $prices = $held === [] ? [] : array_merge(...array_values($held));

                if (count($prices) < self::MIN_SALES) {
                    continue;
                }

                $standings[$day][] = [
                    'id' => $cardId,
                    'value' => $this->median($prices),
                    'sales' => count($prices),
                ];
            }
        }

        return $standings;
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
