<?php

namespace App\Console\Commands;

use App\Actions\Catalog\ImportAllSealed;
use Illuminate\Console\Command;

/**
 * Bulk sealed import across the catalog (Pokémon EN/JP, One Piece, Lorcana),
 * matching each set to its TCGplayer group automatically.
 */
class ImportAllSealedCommand extends Command
{
    protected $signature = 'catalog:import-sealed-all
        {--line= : limit to one product line slug}
        {--no-images : skip downloading product images}
        {--dry-run : report matches + sealed counts without writing}';

    protected $description = 'Import sealed products for every set across TCGplayer-listed games';

    public function handle(ImportAllSealed $import): int
    {
        @ini_set('memory_limit', '1536M');

        $plan = ImportAllSealed::PLAN;
        if ($line = $this->option('line')) {
            $plan = array_values(array_filter($plan, fn ($s) => $s['line'] === $line));
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->line('Bulk sealed import'.($dryRun ? ' (dry run)' : '').'…');

        $r = $import($plan, $dryRun, ! $this->option('no-images'), fn (string $m) => $this->line($m));

        $verb = $dryRun ? 'would import' : 'imported';
        $this->newLine();
        $this->info("Matched {$r['matched']} sets · {$verb} {$r['sealed']} sealed · images: {$r['images']} · valued: {$r['valued']}");

        if ($r['unmatched'] !== []) {
            $this->comment('Unmatched sets ('.count($r['unmatched']).', skipped): '
                .implode(', ', array_slice($r['unmatched'], 0, 25)).(count($r['unmatched']) > 25 ? '…' : ''));
        }

        return self::SUCCESS;
    }
}
