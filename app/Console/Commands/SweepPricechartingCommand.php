<?php

namespace App\Console\Commands;

use App\Actions\Valuation\IngestPricechartingComps;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Pricecharting\PricechartingSoldSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Pull completed sales (eBay + TCGplayer) from PriceCharting product pages and
 * blend them into sealed-product valuations — this backfills older-than-30-day
 * history that eBay's own sold view can't provide. Once per product (marked via
 * external_ids.pc_synced_at); re-run with --force to refresh. Boxes/ETBs first.
 */
class SweepPricechartingCommand extends Command
{
    protected $signature = 'valuation:sweep-pricecharting
        {--id=* : specific catalog item id(s) to process}
        {--type=* : sealed_type(s) to target (default: box-type products)}
        {--line= : product line slug (e.g. pokemon, one-piece)}
        {--limit=25 : max products this run}
        {--force : re-fetch products already synced}
        {--dry-run : resolve + report matches, but do not fetch or write}';

    protected $description = 'Blend PriceCharting completed sales (eBay + TCGplayer) into sealed valuations';

    private const PRIORITY_TYPES = [
        'booster_box', 'booster_box_case', 'elite_trainer_box', 'booster_bundle', 'build_and_battle',
    ];

    public function handle(IngestPricechartingComps $ingest, PricechartingSoldSource $source): int
    {
        $items = $this->targets();

        if ($items->isEmpty()) {
            $this->info('No products to process (use --force, or widen --type/--line).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line("processing {$items->count()} product(s)".($dryRun ? ' (dry run)' : '').'…');

        $matched = 0;
        $ingested = 0;
        $unmatched = 0;

        foreach ($items as $item) {
            $url = $source->resolveUrl($item);

            if ($url === null) {
                $unmatched++;
                $this->line("  · {$item->name}: no PriceCharting match");

                continue;
            }

            $matched++;

            if ($dryRun) {
                $this->line("  ✓ {$item->name} → {$url}");

                continue;
            }

            try {
                $n = $ingest($item);
                $ingested += $n;
                $item->forceFill(['external_ids' => array_merge($item->getAttribute('external_ids') ?? [], ['pc_synced_at' => Carbon::now()->toIso8601String()])])->save();
                $this->info(sprintf('  ✓ %s: %d comp(s) blended', $item->name, $n));
            } catch (Throwable $e) {
                $this->error("  {$item->name}: {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("done — matched {$matched}, unmatched {$unmatched}, comps ingested {$ingested}".($dryRun ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    private function targets()
    {
        if ($ids = $this->option('id')) {
            return CatalogItem::query()->whereIn('id', $ids)->with('set')->get();
        }

        $types = (array) $this->option('type') ?: self::PRIORITY_TYPES;

        return CatalogItem::query()
            ->where('item_type', ItemType::Sealed->value)
            ->when(! $this->option('force'), fn (Builder $q) => $q->whereNull('external_ids->pc_synced_at'))
            ->where(function (Builder $q) use ($types) {
                foreach ($types as $type) {
                    $q->orWhere('attributes->sealed_type', $type);
                }
            })
            ->when($this->option('line'), fn (Builder $q, $slug) => $q->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
            ->with('set')
            ->withMax(['marketValues as sweep_value' => fn (Builder $q) => $q->whereIn('state_key', ['NM', 'SEALED'])], 'median')
            ->orderByDesc('sweep_value')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }
}
