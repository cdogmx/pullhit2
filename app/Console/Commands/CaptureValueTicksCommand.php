<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use App\Models\ValueTick;
use App\Support\Catalog\Subsets;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Record what the featured sets are worth, right now.
 *
 * A set in its first fortnight is repriced through the day — two thirds of the
 * 30th Celebration's cards were recomputed within six hours of this being
 * written — and value_snapshots keeps one row per day, so all of that movement
 * is averaged away before anyone sees it.
 *
 * Scoped to featured sets on purpose. A quarter-hourly reading for the whole
 * catalog is millions of rows a day about cards that last moved in March; the
 * sets worth this resolution are exactly the ones already singled out for the
 * home page, and they stop being written the moment their spot lapses.
 */
class CaptureValueTicksCommand extends Command
{
    protected $signature = 'valuation:tick
        {--set= : one set slug, instead of whatever is featured}
        {--prune=30 : also delete readings older than this many days}';

    protected $description = 'Record an intraday value reading for the featured sets';

    public function handle(): int
    {
        $sets = $this->option('set')
            ? Set::where('slug', $this->option('set'))->get()
            : Set::query()->featured()->get();

        if ($sets->isEmpty()) {
            $this->info('Nothing is featured — no readings taken.');

            return self::SUCCESS;
        }

        // Featuring a parent covers its subsets, the same way the home page does.
        $ids = collect();
        foreach ($sets as $set) {
            $ids = $ids->merge(Set::query()
                ->where('product_line_id', $set->product_line_id)
                ->where('language', $set->language)
                ->where(fn (Builder $q) => $q
                    ->whereKey($set->getKey())
                    ->orWhereIn('name', array_map(
                        fn (string $suffix) => $set->name.' '.$suffix,
                        Subsets::SUFFIXES,
                    )))
                ->pluck('id'));
        }

        // To the minute: the scheduler's cadence is the resolution, and a second
        // firing inside the same minute is the same reading, not a new one.
        $at = now()->startOfMinute();
        $rows = [];

        MarketValue::query()
            ->whereNull('grading_company_id')
            ->where('is_estimated', false)
            ->where('median', '>', 0)
            ->whereIn('catalog_item_id', CatalogItem::whereIn('set_id', $ids->unique())->select('id'))
            ->chunkById(500, function ($values) use (&$rows, $at) {
                foreach ($values as $mv) {
                    $rows[] = [
                        'catalog_item_id' => $mv->catalog_item_id,
                        'state_key' => $mv->state_key,
                        'median_cents' => $mv->median,
                        'for_sale_cents' => $mv->for_sale,
                        'n_sales' => (int) $mv->n_sales,
                        'captured_at' => $at,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ];
                }
            });

        if ($rows === []) {
            $this->info('No real values to read yet.');

            return self::SUCCESS;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            ValueTick::upsert($chunk, ['catalog_item_id', 'state_key', 'captured_at'],
                ['median_cents', 'for_sale_cents', 'n_sales', 'updated_at']);
        }

        $this->info(count($rows).' reading(s) at '.$at->toDateTimeString().'.');

        if ($days = (int) $this->option('prune')) {
            $gone = ValueTick::where('captured_at', '<', now()->subDays($days))->delete();

            if ($gone > 0) {
                $this->line("Pruned {$gone} reading(s) older than {$days} days.");
            }
        }

        return self::SUCCESS;
    }
}
