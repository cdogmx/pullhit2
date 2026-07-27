<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Models\CatalogItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recompute market_values from sale_observations. Run on a schedule (hot items
 * hourly, cold daily — §7) or ad hoc. Use --item to recompute a single item.
 *
 * --stale is the catch-up selector: items whose newest sale observation is more
 * recent than their computed value, plus items holding observations and no value
 * at all. That is exactly what an interrupted bulk ingest leaves behind — sales
 * stored, values not yet derived — and it is also the right filter for a
 * periodic pass, since a card with no new comps needs no work.
 */
class RecomputeValuationsCommand extends Command
{
    protected $signature = 'valuation:recompute
        {--item= : Recompute only this catalog_item id}
        {--stale : Only items whose values are older than their newest observation}
        {--limit= : Stop after this many items}';

    protected $description = 'Recompute market_values from sale_observations';

    public function handle(RecomputeCatalogItem $recompute): int
    {
        $query = CatalogItem::query()->whereHas('saleObservations');

        if ($item = $this->option('item')) {
            $query->whereKey($item);
        }

        if ($this->option('stale')) {
            $query->where(fn (Builder $q) => $q
                // Comps waiting on a first valuation.
                ->whereDoesntHave('marketValues')
                // Or a comp landed after the value was last derived.
                ->orWhereRaw(
                    '(select max(so.created_at) from sale_observations so
                        where so.catalog_item_id = catalog_items.id)
                     > (select min(mv.computed_at) from market_values mv
                        where mv.catalog_item_id = catalog_items.id)'
                ));
        }

        $limit = (int) $this->option('limit');
        $total = (clone $query)->count();
        if ($limit > 0) {
            $total = min($total, $limit);
        }

        if ($total === 0) {
            $this->info('Nothing to recompute.');

            return self::SUCCESS;
        }

        $this->info("Recomputing {$total} item(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $items = 0;
        $states = 0;

        $query->chunkById(100, function ($chunk) use ($recompute, $bar, $limit, &$items, &$states) {
            foreach ($chunk as $catalogItem) {
                $states += $recompute($catalogItem);
                $items++;
                $bar->advance();

                if ($limit > 0 && $items >= $limit) {
                    return false;
                }
            }

            return true;
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Recomputed {$states} priced states across {$items} items.");

        return self::SUCCESS;
    }
}
