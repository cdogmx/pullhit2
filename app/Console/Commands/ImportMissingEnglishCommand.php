<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportPokemonSet;
use App\Models\Set;
use App\Support\Catalog\PokemonTcgClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import every English pokemontcg.io set not yet in the catalog, newest first.
 * Re-checks the DB on each run, so it's fully resumable: if a long import dies
 * partway, re-running only picks up the sets still missing. Idempotent upserts.
 */
class ImportMissingEnglishCommand extends Command
{
    protected $signature = 'catalog:import-missing-en
        {--no-prices : skip valuation seeding}
        {--no-images : skip downloading card images}
        {--limit=0 : only import the first N missing sets (0 = all)}';

    protected $description = 'Import every English pokemontcg.io set not yet in the catalog (newest first).';

    public function handle(ImportPokemonSet $import, PokemonTcgClient $client): int
    {
        @ini_set('memory_limit', '1536M');

        $withPrices = ! $this->option('no-prices');
        $withImages = ! $this->option('no-images');

        $have = Set::query()->whereNotNull('external_ids->ptcgio_id')->get()
            ->map(fn (Set $s) => $s->external_ids['ptcgio_id'] ?? null)
            ->filter()->all();

        $missing = collect($client->allSets())
            ->reject(fn ($s) => in_array($s['id'], $have, true))
            ->values();

        if (($limit = (int) $this->option('limit')) > 0) {
            $missing = $missing->take($limit);
        }

        $total = $missing->count();
        $this->info("{$total} sets to import (newest first).");

        $items = 0;
        $valued = 0;
        $ok = 0;
        $fail = 0;

        foreach ($missing as $i => $s) {
            $n = $i + 1;
            $this->line("[{$n}/{$total}] <info>{$s['id']}</info> — {$s['name']} ({$s['releaseDate']})");

            try {
                $r = $import($s['id'], $withPrices, $withImages);
                $items += $r['items'];
                $valued += $r['valued'];
                $ok++;
                $this->line("    {$r['items']} items, {$r['valued']} valued, {$r['images']} images");
            } catch (Throwable $e) {
                $fail++;
                $this->error("    failed: {$e->getMessage()}");
            }
        }

        $this->info("Done — {$ok} sets ok, {$fail} failed; {$items} items, {$valued} valued.");

        return self::SUCCESS;
    }
}
