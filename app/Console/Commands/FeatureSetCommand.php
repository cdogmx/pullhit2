<?php

namespace App\Console\Commands;

use App\Models\Set;
use Illuminate\Console\Command;

/**
 * Put a set on the home page for a while.
 *
 * A release everyone is opening is the reason people visit that week, and it is
 * otherwise buried in "Trending" behind cards with years of accumulated views.
 *
 * The spot carries an expiry rather than a flag, so a set that stopped being
 * news drops off by itself. Featuring a parent set is enough — its subsets come
 * with it, because someone told to look at the 30th Celebration means its
 * Classic Collection and its promos too.
 */
class FeatureSetCommand extends Command
{
    protected $signature = 'catalog:feature-set
        {set : the set slug}
        {--days=21 : how long it keeps the spot}
        {--blurb= : the line under the heading}
        {--clear : take it down now instead}';

    protected $description = 'Give a set its own section on the home page, for a limited time';

    public function handle(): int
    {
        $set = Set::where('slug', $this->argument('set'))->first();

        if (! $set) {
            $this->error("No set with slug {$this->argument('set')}.");

            return self::FAILURE;
        }

        $clear = (bool) $this->option('clear');
        $until = now()->addDays(max(1, (int) $this->option('days')));

        $set->forceFill($clear
            ? ['featured_until' => null, 'featured_blurb' => null]
            : array_filter([
                'featured_until' => $until,
                'featured_blurb' => $this->option('blurb') ?: $set->featured_blurb,
            ], fn ($v) => $v !== null),
        )->save();

        if ($clear) {
            $set->forceFill(['featured_blurb' => null])->save();
        }

        $this->table(['set', 'featured', 'blurb'], [[
            $set->name,
            $clear ? 'no' : 'until '.$until->toDayDateTimeString(),
            $set->fresh()->featured_blurb ?: '—',
        ]]);

        // The home page caches its sections for ten minutes.
        $this->line('<comment>The home page caches for 10 minutes; it will pick this up on the next miss.</comment>');

        return self::SUCCESS;
    }
}
