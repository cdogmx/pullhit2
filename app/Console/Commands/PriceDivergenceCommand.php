<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Support\Valuation\RawAnchor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * How far our raw prices sit from an outside source, card by card.
 *
 * Read-only. Everything else in this area fixes a MECHANISM we happened to
 * find — a grader brand the parser did not know, a lot that read as a single,
 * a band anchored on the number it was meant to check. This measures the
 * PROBLEM instead, so we can tell whether those mechanisms were most of it or
 * whether something we have not met yet is still moving prices.
 *
 * PriceCharting's ungraded price is the comparison because nothing we compute
 * feeds it. It is not truth — it has its own thin-sample cards and its own lag
 * — so a single card disagreeing means little. The distribution is the point:
 * a healthy catalog is a tall pile near 1x with thin tails, and a fat upper
 * tail means slab or lot money is still landing in raw bands somewhere.
 *
 * Ratios run our-value / theirs, so 12.5 means we say a $4 card is worth $53.
 */
class PriceDivergenceCommand extends Command
{
    protected $signature = 'valuation:price-divergence
        {--min-cents=300 : ignore cards cheaper than this, where ratios are noise}
        {--ratio=3 : list cards at or beyond this multiple, either way}
        {--limit=30 : how many of the worst to print}
        {--set= : only this set, by slug}';

    protected $description = 'Compare our raw prices against PriceCharting and report the spread';

    public function handle(RawAnchor $anchor): int
    {
        $minCents = (int) $this->option('min-cents');
        $threshold = (float) $this->option('ratio');
        $limit = (int) $this->option('limit');

        $compared = 0;
        $noReference = 0;
        /** @var array<string, int> $buckets */
        $buckets = ['under 0.2x' => 0, '0.2–0.5x' => 0, '0.5–2x' => 0, '2–5x' => 0, '5–10x' => 0, 'over 10x' => 0];
        /** @var array<int, array{ratio: float, ours: int, theirs: int, item: CatalogItem}> $outliers */
        $outliers = [];

        CatalogItem::query()
            ->whereNotNull('set_id')
            ->whereNotNull('number')
            ->when($this->option('set'), fn (Builder $q, $slug) => $q->whereHas('set', fn (Builder $s) => $s->where('slug', $slug)))
            // Ordered by set so the anchor's per-set PriceCharting cache stays
            // warm; jumping between sets would re-read the same rows all day.
            ->orderBy('set_id')
            ->with('set:id,name')
            ->chunk(500, function ($items) use ($anchor, $minCents, $threshold, &$compared, &$noReference, &$buckets, &$outliers) {
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

                    $theirs = $anchor->reference($item);

                    if ($theirs === null || $theirs < $minCents) {
                        $noReference++;

                        continue;
                    }

                    $compared++;
                    $ratio = $mine / $theirs;

                    $buckets[match (true) {
                        $ratio < 0.2 => 'under 0.2x',
                        $ratio < 0.5 => '0.2–0.5x',
                        $ratio < 2 => '0.5–2x',
                        $ratio < 5 => '2–5x',
                        $ratio < 10 => '5–10x',
                        default => 'over 10x',
                    }]++;

                    if ($ratio >= $threshold || $ratio <= 1 / $threshold) {
                        $outliers[] = ['ratio' => $ratio, 'ours' => $mine, 'theirs' => $theirs, 'item' => $item];
                    }
                }
            });

        if ($compared === 0) {
            $this->warn('Nothing to compare — no cards had both a real raw value and a PriceCharting price.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("Compared <options=bold>{$compared}</> cards against PriceCharting.");
        $this->line("Skipped {$noReference} with no usable reference price.");
        $this->newLine();

        $this->table(['spread (ours / theirs)', 'cards', 'share'], array_map(
            fn ($k, $v) => [$k, number_format($v), sprintf('%.1f%%', $v / $compared * 100)],
            array_keys($buckets),
            $buckets,
        ));

        // The upper tail is the one that costs money: it is where a slab or a
        // lot is still pricing a raw card, and where somebody is told to hold
        // something worth a fraction of what we said.
        $high = $buckets['2–5x'] + $buckets['5–10x'] + $buckets['over 10x'];
        $low = $buckets['under 0.2x'] + $buckets['0.2–0.5x'];

        $this->line(sprintf('Over-priced by 2x or more: <options=bold>%s</> (%.1f%%)   ·   under half: %s (%.1f%%)',
            number_format($high), $high / $compared * 100,
            number_format($low), $low / $compared * 100));

        usort($outliers, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);

        if ($outliers !== []) {
            $this->newLine();
            $this->line("Worst offenders (at or beyond {$threshold}x):");
            $this->table(['ratio', 'ours', 'theirs', 'card', 'set'], array_map(
                fn ($o) => [
                    sprintf('%.1fx', $o['ratio']),
                    '$'.number_format($o['ours'] / 100, 2),
                    '$'.number_format($o['theirs'] / 100, 2),
                    mb_substr($o['item']->name, 0, 32).' #'.$o['item']->number,
                    mb_substr((string) $o['item']->set?->name, 0, 26),
                ],
                array_slice($outliers, 0, $limit),
            ));

            $this->line(sprintf('%s card(s) past the threshold in total.', number_format(count($outliers))));
        }

        return self::SUCCESS;
    }
}
