<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill catalog_items.slug, unique within each set, from the card's display
 * name + collector number. Idempotent: only fills missing slugs unless --fresh.
 */
class BackfillCardSlugsCommand extends Command
{
    protected $signature = 'cards:backfill-slugs {--fresh : Recompute every slug, not just missing ones}';

    protected $description = 'Generate per-card URL slugs (unique within a set)';

    public function handle(): int
    {
        $fresh = (bool) $this->option('fresh');
        $bar = $this->output->createProgressBar(Set::count());
        $total = 0;

        Set::orderBy('id')->cursor()->each(function (Set $set) use ($fresh, &$total, $bar) {
            $used = [];
            $updates = [];

            CatalogItem::where('set_id', $set->id)
                ->orderBy('id')
                ->get(['id', 'set_id', 'name', 'number', 'attributes', 'slug'])
                ->each(function (CatalogItem $item) use ($fresh, &$used, &$updates) {
                    // Keep an existing slug (and reserve it) unless --fresh.
                    if (! $fresh && $item->slug) {
                        $used[$item->slug] = true;

                        return;
                    }

                    $base = $item->slugBase();
                    $slug = $base;
                    $n = 2;
                    while (isset($used[$slug])) {
                        $slug = "{$base}-{$n}";
                        $n++;
                    }

                    $used[$slug] = true;
                    $updates[$item->id] = $slug;
                });

            foreach ($updates as $id => $slug) {
                DB::table('catalog_items')->where('id', $id)->update(['slug' => $slug]);
            }

            $total += count($updates);
            $bar->advance();
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Slugged {$total} cards.");

        return self::SUCCESS;
    }
}
