<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportTcgcsvSet;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Import a set from TCGCSV by TCGplayer group id — for sets the per-game APIs
 * haven't published yet (they lag release by weeks). Cards only; sealed products
 * come via `catalog:import-sealed`. Idempotent; the game's own importer
 * (`catalog:import-set`, `catalog:import-lorcana`) refines the same rows once it
 * catches up.
 */
class ImportTcgcsvSetCommand extends Command
{
    protected $signature = 'catalog:import-tcgcsv
        {groupIds* : TCGplayer group ids (e.g. 24688 "ME05: Pitch Black", 24666 "Attack of the Vine!")}
        {--game=pokemon : which game the group belongs to (pokemon|lorcana)}
        {--no-prices : skip valuation seeding}
        {--no-images : skip downloading card images}
        {--skip-numbers= : collector numbers to leave alone, comma-separated or a range like 037-063}';

    protected $description = 'Import set(s) from TCGCSV by group id (for sets the per-game APIs lack)';

    public function handle(ImportTcgcsvSet $import): int
    {
        @ini_set('memory_limit', '1024M');

        try {
            $game = TcgcsvGame::fromSlug((string) $this->option('game'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $withPrices = ! $this->option('no-prices');
        $withImages = ! $this->option('no-images');
        $skip = $this->skipNumbers((string) $this->option('skip-numbers'));

        if ($skip !== []) {
            $this->line('Leaving '.count($skip).' number(s) alone: '.implode(', ', array_slice($skip, 0, 6))
                .(count($skip) > 6 ? ' …' : ''));
        }

        foreach ($this->argument('groupIds') as $id) {
            $this->line("Importing <info>{$game->value}</info> group <info>{$id}</info>…");

            try {
                $r = $import((int) $id, $withPrices, $withImages, $game, $skip);
                $this->line("  {$r['set']}: {$r['items']} items, {$r['valued']} valued, {$r['images']} images");
            } catch (Throwable $e) {
                $this->error("  {$id} failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * "037-063" or "37,38,39" — a range is the common case, because the overlap
     * between a promo group and a set we curate by hand tends to be contiguous.
     *
     * @return array<int, string>
     */
    private function skipNumbers(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $out = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                foreach (range((int) $m[1], (int) $m[2]) as $n) {
                    $out[] = (string) $n;
                }

                continue;
            }

            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
