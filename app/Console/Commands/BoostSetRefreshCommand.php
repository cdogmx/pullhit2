<?php

namespace App\Console\Commands;

use App\Models\Set;
use Illuminate\Console\Command;

/**
 * Refetch a set's prices more often, for a while.
 *
 * A set in its first week is a different market from the catalog around it:
 * everything is being priced at once, and the twelve-hour view TTL that is right
 * for 71,000 settled cards leaves a brand-new chase card hours out of date.
 *
 * The boost carries an expiry rather than a flag someone has to remember to turn
 * off. A permanent ten-minute TTL on a settled set is a way to spend a scarce
 * scrape budget re-confirming a price that has not moved.
 *
 * It lives in the database, not config, deliberately: the site reads the same
 * database this command writes, so a boost takes effect without a deploy — which
 * is the point of reaching for it on release day.
 */
class BoostSetRefreshCommand extends Command
{
    protected $signature = 'valuation:boost-set
        {set* : set slugs}
        {--minutes=10 : how stale a price may get before a view refetches it}
        {--days=7 : how long the boost lasts}
        {--clear : end the boost now instead}';

    protected $description = 'Refetch a set\'s prices more often for a limited time';

    public function handle(): int
    {
        $slugs = (array) $this->argument('set');
        $sets = Set::whereIn('slug', $slugs)->get();

        if ($missing = array_diff($slugs, $sets->pluck('slug')->all())) {
            $this->error('No set with slug: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $clear = (bool) $this->option('clear');
        $minutes = max(1, (int) $this->option('minutes'));
        $until = now()->addDays(max(1, (int) $this->option('days')));
        $rows = [];

        foreach ($sets as $set) {
            $set->forceFill($clear
                ? ['refresh_minutes' => null, 'refresh_boost_until' => null]
                : ['refresh_minutes' => $minutes, 'refresh_boost_until' => $until],
            )->save();

            $rows[] = [
                $set->name,
                $set->catalogItems()->count(),
                $clear ? '—' : $minutes.' min',
                $clear ? 'cleared' : $until->toDayDateTimeString(),
            ];
        }

        $this->table(['set', 'cards', 'refresh', 'until'], $rows);

        return self::SUCCESS;
    }
}
