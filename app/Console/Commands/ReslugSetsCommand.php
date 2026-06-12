<?php

namespace App\Console\Commands;

use App\Models\Set;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Recompute set slugs to the human-readable, SEO-friendly form (name → slug,
 * e.g. "surging-sparks"; non-English sets get a language suffix). Run once after
 * switching the slug scheme; follow with `catalog:rehash` since the slug feeds
 * the identity hash.
 */
class ReslugSetsCommand extends Command
{
    protected $signature = 'catalog:reslug-sets {--dry-run : Report changes without writing}';

    protected $description = 'Recompute set slugs from their names (SEO-friendly).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;

        foreach (Set::all() as $set) {
            $slug = Str::slug($set->name) ?: $set->slug;
            if ($set->language && $set->language !== 'en') {
                $slug .= "-{$set->language}";
            }

            if ($slug === $set->slug) {
                continue;
            }

            $this->line("  {$set->slug}  →  {$slug}");
            $changed++;

            if (! $dry) {
                $set->slug = $slug;
                $set->save();
            }
        }

        $this->info("{$changed} sets ".($dry ? 'would be reslugged (dry-run)' : 'reslugged').'.');

        return self::SUCCESS;
    }
}
