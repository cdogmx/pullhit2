<?php

namespace App\Console\Commands;

use App\Actions\Catalog\BackfillTcgplayerImages;
use App\Models\Set;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill images + rarity for a set's image-less cards from TCGCSV (TCGplayer),
 * for sets where pokemontcg.io is behind on secret rares. Matches by collector
 * number; only touches rows missing an image. Re-runnable.
 */
class BackfillTcgplayerImagesCommand extends Command
{
    protected $signature = 'catalog:backfill-tcg-images
        {setId : our catalog Set id}
        {groupId : TCGplayer group id for the set (e.g. 24541 for ME: Ascended Heroes)}
        {--category=3 : TCGplayer category id (3 = English Pokémon, 85 = Japanese)}
        {--no-rarity : do not fill in missing/Unknown rarities}
        {--dry-run : report what would change without writing}';

    protected $description = 'Backfill card images/rarity from TCGCSV for a set pokemontcg.io has not fully published';

    public function handle(BackfillTcgplayerImages $backfill): int
    {
        @ini_set('memory_limit', '1024M');

        $set = Set::find((int) $this->argument('setId'));

        if (! $set) {
            $this->error("Set {$this->argument('setId')} not found.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line("Backfilling <info>{$set->name}</info> from TCGplayer group <info>{$this->argument('groupId')}</info>".($dryRun ? ' (dry run)' : '').'…');

        try {
            $r = $backfill(
                set: $set,
                groupId: (int) $this->argument('groupId'),
                category: (int) $this->option('category'),
                fillRarity: ! $this->option('no-rarity'),
                dryRun: $dryRun,
            );
        } catch (Throwable $e) {
            $this->error("  failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $verb = $dryRun ? 'would store' : 'stored';
        $this->line("  candidates: {$r['candidates']} | matched: {$r['matched']} | {$verb}: {$r['stored']} | rarities filled: {$r['rarities']}");

        if ($r['unmatched'] !== []) {
            $this->warn('  unmatched numbers (not in TCGplayer group): '.implode(', ', $r['unmatched']));
        }

        return self::SUCCESS;
    }
}
