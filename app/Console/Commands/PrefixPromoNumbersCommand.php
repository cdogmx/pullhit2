<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\ItemIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Give a promo set's cards the prefix they are actually printed with.
 *
 * Every Black Star Promos set stores the printed collector number — SWSH050,
 * XY01, SM01 — except Scarlet & Violet, whose 200 cards were imported as bare
 * integers (1, 74, 196) because pokemontcg.io publishes them that way while
 * still calling the card svp-74. The number is how collectors search, so
 * "SVP 074" found nothing, and every one of those bare numbers collided with a
 * card of the same number in some other set.
 *
 * Renumbering moves each card's URL (the slug is built from name + number), so
 * the old slugs are kept as aliases and redirect — see CatalogItem::booted.
 */
class PrefixPromoNumbersCommand extends Command
{
    protected $signature = 'catalog:prefix-promo-numbers
        {set : set slug, e.g. scarlet-violet-black-star-promos}
        {--prefix= : the printed prefix (defaults to the set code minus "PR-")}
        {--pad=3 : digits to zero-pad the number to}
        {--execute : apply the changes (otherwise report only)}';

    protected $description = 'Prefix a promo set\'s bare collector numbers with the printed prefix (e.g. 74 → SVP074)';

    public function handle(ItemIdentity $identity): int
    {
        $set = Set::where('slug', $this->argument('set'))->first();

        if (! $set) {
            $this->error("No set with slug [{$this->argument('set')}].");

            return self::FAILURE;
        }

        $prefix = strtoupper((string) ($this->option('prefix')
            ?: preg_replace('/^PR-/', '', (string) $set->code).'P'));
        $pad = max(1, (int) $this->option('pad'));
        $execute = (bool) $this->option('execute');

        $this->line($execute ? '<comment>EXECUTING</comment>' : '<info>DRY RUN</info> — pass --execute to apply');
        $this->line("set: {$set->name} ({$set->code})   prefix: {$prefix}   pad: {$pad}");

        $items = CatalogItem::with(['vertical', 'productLine', 'set'])
            ->where('set_id', $set->id)
            ->get();

        $planned = [];

        foreach ($items as $item) {
            $number = (string) $item->number;

            // Only bare integers are ambiguous. Anything already carrying the
            // prefix (or any other letter) is left exactly as it is, so a re-run
            // is a no-op rather than "SVPSVP074".
            if (! preg_match('/^[0-9]+$/', $number)) {
                continue;
            }

            $planned[] = [
                'item' => $item,
                'from' => $number,
                'to' => $prefix.str_pad($number, $pad, '0', STR_PAD_LEFT),
            ];
        }

        $this->line('cards to renumber: '.count($planned).' of '.$items->count());

        if ($planned === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        foreach (array_slice($planned, 0, 5) as $row) {
            $this->line("   #{$row['from']}  ->  #{$row['to']}   {$row['item']->name}");
        }

        // A renumber that lands on a number already used in this set would make
        // two cards indistinguishable; refuse rather than merge them.
        $existing = $items->pluck('number')->map(fn ($n) => (string) $n)->all();
        $clashes = array_intersect(array_column($planned, 'to'), $existing);

        if ($clashes !== []) {
            $this->error('ABORT: these target numbers already exist in the set: '.implode(', ', $clashes));

            return self::FAILURE;
        }

        if (! $execute) {
            $this->newLine();
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($planned));

        foreach ($planned as $row) {
            /** @var CatalogItem $item */
            $item = $row['item'];

            DB::transaction(function () use ($item, $row, $identity) {
                // Number feeds the slug AND the identity hash, so all three move
                // together. Saving with the new number first lets buildUniqueSlug
                // and the alias hook see the final state.
                $item->number = $row['to'];
                $item->slug = null;
                $item->slug = $item->buildUniqueSlug();
                $item->forceFill($identity->forItem($item))->save();
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('renumbered '.count($planned).' card(s); old URLs now redirect.');

        return self::SUCCESS;
    }
}
