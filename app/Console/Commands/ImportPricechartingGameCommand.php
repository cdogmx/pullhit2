<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportPricechartingGame;
use App\Support\Pricecharting\CsvClient;
use App\Support\Pricecharting\GameImportSpec;
use Illuminate\Console\Command;

/**
 * Build a TCG catalog from its PriceCharting price-guide CSV.
 *
 *   php artisan catalog:import-pc-game one-piece --limit=200   # validate a slice
 *   php artisan catalog:import-pc-game one-piece               # full import
 */
class ImportPricechartingGameCommand extends Command
{
    protected $signature = 'catalog:import-pc-game
        {game : one-piece | magic | yugioh}
        {--path= : Read a local CSV instead of downloading}
        {--no-images : Skip pulling TCGplayer images}
        {--no-prices : Skip seeding values from the CSV}
        {--limit= : Only import the first N singles (validation runs)}';

    protected $description = 'Build a TCG catalog (One Piece, Magic, Yu-Gi-Oh, …) from a PriceCharting price-guide CSV.';

    public function handle(CsvClient $client, ImportPricechartingGame $import): int
    {
        @ini_set('memory_limit', '1536M');

        $spec = GameImportSpec::forGame((string) $this->argument('game'));

        $path = $this->option('path');
        $this->line($path ? "Reading {$path}…" : "Downloading PriceCharting {$spec->category}…");
        $csv = $path ? (string) file_get_contents($path) : $client->fetch($spec->category);

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $this->line("Importing {$spec->lineName}".($limit ? " (first {$limit})" : '').'…');
        $stats = $import(
            $spec,
            $csv,
            withImages: ! $this->option('no-images'),
            withPrices: ! $this->option('no-prices'),
            limit: $limit,
        );

        $this->info("Done — {$stats['sets']} sets, {$stats['items']} cards, "
            ."{$stats['images']} images, {$stats['priced']} priced.");

        return self::SUCCESS;
    }
}
