<?php

namespace App\Console\Commands;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes distributor "cases" from the sealed catalog — a "{Product} Case" is a
 * shipping case of N units, not a single openable product, so it pollutes browse
 * and breaks the per-pack rip-EV math. Matches products whose name ENDS in the
 * word "Case" (ignoring trailing "(Exclusive)"/"[variant]" qualifiers), so names
 * like "Special Case File" or "On the Case Figure Collection" are left alone.
 * Never deletes a product any user owns. Deletes are cascaded to values/comps.
 */
class PruneSealedCasesCommand extends Command
{
    protected $signature = 'sealed:prune-cases {--dry-run : list what would be removed without deleting}';

    protected $description = 'Remove distributor case products from the sealed catalog';

    /** Name ends in the word "case", optionally trailed by (…)/[…] qualifiers. */
    private const ENDS_IN_CASE = '/(^|\s)case(\s*(\([^)]*\)|\[[^\]]*\]))*\s*$/i';

    public function handle(): int
    {
        // Prefilter in SQL (portable LIKE), then match "ends in Case" in PHP so
        // "Special Case File" / "On the Case Collection" are left alone.
        $matches = CatalogItem::query()
            ->where('item_type', ItemType::Sealed->value)
            ->where('name', 'like', '%case%')
            ->get(['id', 'name'])
            ->filter(fn (CatalogItem $c) => preg_match(self::ENDS_IN_CASE, (string) $c->name) === 1);

        // Safety: never delete a product a collector actually owns.
        $owned = DB::table('collection_items')
            ->whereIn('catalog_item_id', $matches->pluck('id'))
            ->distinct()->pluck('catalog_item_id');

        $ids = $matches->pluck('id')->diff($owned)->values();
        $count = $ids->count();

        if ($count === 0) {
            $this->info('No case products to remove.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY RUN — would remove {$count} case product(s):");
            $matches->whereIn('id', $ids)->sortBy('name')
                ->each(fn (CatalogItem $c) => $this->line("  {$c->name}"));

            return self::SUCCESS;
        }

        $values = DB::table('market_values')->whereIn('catalog_item_id', $ids)->count();
        $comps = DB::table('sale_observations')->whereIn('catalog_item_id', $ids)->count();

        DB::transaction(fn () => CatalogItem::whereIn('id', $ids)->delete());

        $this->info("Removed {$count} case product(s) (cascaded {$values} market values + {$comps} comps).");

        return self::SUCCESS;
    }
}
