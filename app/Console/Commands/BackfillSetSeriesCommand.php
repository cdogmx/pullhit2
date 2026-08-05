<?php

namespace App\Console\Commands;

use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Console\Command;

/**
 * Give every set a series.
 *
 * Browse drills brand → series → set, and BrowseTiles::series() only lists sets
 * with a series. A set without one therefore appears under no tile and cannot be
 * reached by drilling at all — 101 Pokémon sets (15,589 cards, all Japanese)
 * were invisible that way, including XY Promos and Shiny Treasure ex. They were
 * reachable only by first switching the language selector to Japanese, which
 * drops the series count to zero and collapses browse to a flat set list.
 *
 * A Japanese set's era is in its code (SV.., SM.., XY..), so that is used where
 * present. The handful with no code are placed by release date, which is
 * approximate at the era boundaries — run without --execute to review the plan.
 */
class BackfillSetSeriesCommand extends Command
{
    protected $signature = 'catalog:backfill-series
        {--line=pokemon : product line slug}
        {--execute : apply the changes (otherwise report only)}';

    protected $description = 'Give series-less sets a series so browse can reach them';

    /**
     * Set-code prefix → series. Longest prefix wins, so SV/SM are tested before
     * the bare S of the Sword & Shield era.
     *
     * @var array<string, string>
     */
    private const BY_CODE = [
        'SV' => 'Scarlet & Violet',
        'SM' => 'Sun & Moon',
        'XY' => 'XY',
        'CP' => 'XY', // Concept Pack, CP1–CP6, all XY-era.
        'DP' => 'Diamond & Pearl',
        'BW' => 'Black & White',
        'HS' => 'HeartGold & SoulSilver',
        'PL' => 'Platinum',
        'M' => 'Mega Evolution',
        'S' => 'Sword & Shield',
        'L' => 'Sword & Shield',
    ];

    /**
     * Release date → series, for sets with no code. Upper bound is exclusive.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const BY_DATE = [
        ['1998-01-01', 'Base'],
        ['1999-10-01', 'Gym'],
        ['2001-12-01', 'Neo'],
        ['2003-07-01', 'E-Card'],
        ['2007-01-01', 'EX'],
        ['2009-02-01', 'Diamond & Pearl'],
        ['2010-02-01', 'Platinum'],
        ['2011-01-01', 'HeartGold & SoulSilver'],
        ['2013-10-01', 'Black & White'],
        ['2017-01-01', 'XY'],
        ['2020-01-01', 'Sun & Moon'],
        ['2023-01-01', 'Sword & Shield'],
        ['2025-08-01', 'Scarlet & Violet'],
        ['9999-01-01', 'Mega Evolution'],
    ];

    public function handle(): int
    {
        $line = ProductLine::where('slug', $this->option('line'))->first();

        if (! $line) {
            $this->error("No product line [{$this->option('line')}].");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $this->line($execute ? '<comment>EXECUTING</comment>' : '<info>DRY RUN</info> — pass --execute to apply');

        $sets = Set::where('product_line_id', $line->id)
            ->where(fn ($q) => $q->whereNull('series')->orWhere('series', ''))
            ->orderBy('released_at')
            ->get();

        if ($sets->isEmpty()) {
            $this->info('Every set already has a series.');

            return self::SUCCESS;
        }

        $rows = [];
        $counts = [];

        foreach ($sets as $set) {
            [$series, $why] = $this->seriesFor($set);
            $rows[] = [$set, $series, $why];
            $counts[$series] = ($counts[$series] ?? 0) + 1;
        }

        $this->line('series-less sets: '.$sets->count());
        $this->newLine();

        foreach ($counts as $series => $n) {
            $this->line('   '.str_pad($series, 26).$n.' sets');
        }

        // The date-derived ones are the guesses; show them all so a wrong era is
        // easy to spot and correct before it ships.
        $guessed = array_filter($rows, fn ($r) => $r[2] === 'date');

        if ($guessed !== []) {
            $this->newLine();
            $this->line('placed by release date (approximate — no set code to go on):');
            foreach ($guessed as [$set, $series]) {
                $this->line('   '.str_pad(substr($set->name, 0, 38), 40)
                    .str_pad((string) ($set->released_at?->toDateString() ?? '—'), 13).$series);
            }
        }

        if (! $execute) {
            $this->newLine();
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        foreach ($rows as [$set, $series]) {
            $set->forceFill(['series' => $series])->save();
        }

        $this->newLine();
        $this->info('set a series on '.count($rows).' set(s).');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string} the series and how it was decided
     */
    private function seriesFor(Set $set): array
    {
        $code = strtoupper((string) $set->code);

        if ($code !== '') {
            // Longest prefix first so "SV"/"SM" beat "S".
            $prefixes = array_keys(self::BY_CODE);
            usort($prefixes, fn ($a, $b) => strlen($b) <=> strlen($a));

            foreach ($prefixes as $prefix) {
                if (str_starts_with($code, $prefix)) {
                    return [self::BY_CODE[$prefix], 'code'];
                }
            }
        }

        $released = $set->released_at?->toDateString();

        if ($released !== null) {
            foreach (self::BY_DATE as [$before, $series]) {
                if ($released < $before) {
                    return [$series, 'date'];
                }
            }
        }

        // No code and no date to reason from. "Other" already exists for exactly
        // this, and a set under it is at least reachable.
        return ['Other', 'fallback'];
    }
}
