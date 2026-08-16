<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\ItemIdentity;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give a set's pattern printings the name they are actually printed with.
 *
 * The Mega Evolution era prints a card's reverse-holo slot in one of several
 * patterns, and TCGplayer names each one — "Erika's Oddish (Love Ball)". They
 * arrived here flattened: all five ball patterns became a single finish of
 * "ball", so 130 Ascended Heroes cards displayed as "(Ball)" with no way to tell
 * a Love Ball from a Dusk Ball or to search for either. The Team Rocket pattern
 * landed somewhere else again, as a plain reverse holo.
 *
 * Nothing was lost, only labelled: no card carries two ball patterns, so the
 * collector number identifies which one each row is. That makes this a relabel
 * rather than a re-import.
 */
class SyncPatternFinishesCommand extends Command
{
    protected $signature = 'catalog:sync-pattern-finishes
        {set : set slug}
        {--execute : apply the changes (otherwise report only)}';

    protected $description = 'Replace flattened pattern finishes with the printing names TCGplayer publishes';

    /** Upstream labels that are a reverse-holo pattern rather than a product tag. */
    private const PATTERNS = [
        'Poke Ball', 'Poké Ball', 'Dusk Ball', 'Love Ball', 'Friend Ball',
        'Quick Ball', 'Heal Ball', 'Great Ball', 'Ultra Ball', 'Master Ball',
        'Team Rocket',
    ];

    /** Finishes we replace. Anything else is left alone. */
    private const FLATTENED = ['ball'];

    public function handle(ItemIdentity $identity, TcgcsvClient $client): int
    {
        $set = Set::where('slug', $this->argument('set'))->first();

        if (! $set) {
            $this->error("No set with slug [{$this->argument('set')}].");

            return self::FAILURE;
        }

        $groupId = Arr::get((array) $set->external_ids, 'tcgplayer_group_id');

        if (! $groupId) {
            $this->error("Set [{$set->slug}] has no tcgplayer_group_id to compare against.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $this->line($execute ? '<comment>EXECUTING</comment>' : '<info>DRY RUN</info> — pass --execute to apply');
        $this->line("set: {$set->name}   TCGplayer group: {$groupId}");

        // number => the pattern printed on it, from the upstream product names.
        $upstream = [];
        $ambiguous = [];

        foreach ($client->products((int) $groupId, 3) as $product) {
            if (! preg_match('/\(([^)]+)\)\s*$/', (string) ($product['name'] ?? ''), $m)) {
                continue;
            }

            $label = trim($m[1]);

            if (! in_array($label, self::PATTERNS, true)) {
                continue; // "Sam's Club", "Exclusive", "3-Tab" — not a printing.
            }

            $raw = Arr::get(collect($product['extendedData'] ?? [])->keyBy('name')->get('Number', []), 'value');
            $number = ltrim(explode('/', (string) $raw)[0], '0');

            if ($number === '') {
                continue;
            }

            // Two different patterns on one number would make the mapping a guess.
            if (isset($upstream[$number]) && $upstream[$number] !== $label) {
                $ambiguous[$number] = [$upstream[$number], $label];
            }

            $upstream[$number] = $label;
        }

        $this->line('upstream pattern printings: '.count($upstream));

        if ($ambiguous !== []) {
            $this->warn('skipping numbers carrying more than one pattern (cannot tell which row is which):');
            foreach ($ambiguous as $number => $labels) {
                $this->line("   #{$number}: ".implode(' + ', $labels));
            }
        }

        $planned = [];

        foreach (CatalogItem::with(['vertical', 'productLine', 'set'])->where('set_id', $set->id)->get() as $item) {
            $attributes = $item->getAttribute('attributes') ?? [];
            $number = (string) $item->number;
            $label = $upstream[$number] ?? null;

            if ($label === null || isset($ambiguous[$number])) {
                continue;
            }

            $finish = $attributes['finish'] ?? null;
            $variant = $attributes['variant'] ?? null;
            // "Poké Ball" → poke_ball. Not Str::snake, which would see the
            // underscore we just inserted and add a second one: poke__ball.
            $target = Str::lower(str_replace(['é', ' ', '-'], ['e', '_', '_'], $label));

            // A flattened ball finish becomes the specific ball.
            if ($finish !== null && in_array($finish, self::FLATTENED, true) && $label !== 'Team Rocket') {
                $planned[] = [$item, ['finish' => $target] + $attributes, "finish {$finish} → {$target}"];

                continue;
            }

            // The Team Rocket pattern was filed as an ordinary reverse holo. Only
            // convert a reverse holo on a number upstream says has that pattern —
            // this set also has real reverse holos that must stay as they are.
            if ($label === 'Team Rocket' && $variant === 'reverse_holo' && empty($finish)) {
                $planned[] = [
                    $item,
                    ['variant' => 'normal', 'finish' => 'team_rocket'] + $attributes,
                    'reverse_holo → finish team_rocket',
                ];
            }
        }

        $this->line('rows to relabel: '.count($planned));

        $summary = [];
        foreach ($planned as [, , $what]) {
            $summary[$what] = ($summary[$what] ?? 0) + 1;
        }
        foreach ($summary as $what => $n) {
            $this->line('   '.str_pad($what, 34).$n);
        }

        if ($planned === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        // Every relabel must land on an identity nothing else holds.
        $taken = [];
        $collisions = 0;

        foreach ($planned as [$item, $attributes]) {
            $keys = $identity->for(
                verticalSlug: $item->vertical->slug,
                productLineSlug: $item->productLine->slug,
                setKey: $item->set?->slug,
                itemType: $item->item_type->value,
                name: $item->name,
                number: $item->number,
                attributes: $attributes,
            );

            $clash = CatalogItem::where('identity_hash', $keys['identity_hash'])
                ->whereKeyNot($item->getKey())->exists();

            if ($clash || isset($taken[$keys['identity_hash']])) {
                $this->error("   collision: {$item->name} #{$item->number}");
                $collisions++;
            }

            $taken[$keys['identity_hash']] = true;
        }

        if ($collisions > 0) {
            $this->error("ABORT: {$collisions} relabel(s) would collide with an existing row.");

            return self::FAILURE;
        }

        if (! $execute) {
            $this->newLine();
            $this->line('sample:');
            foreach (array_slice($planned, 0, 5) as [$item, $attributes]) {
                $this->line("   #{$item->number}  {$item->name}  →  finish={$attributes['finish']}");
            }
            $this->newLine();
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($planned));

        foreach ($planned as [$item, $attributes]) {
            DB::transaction(function () use ($item, $attributes, $identity) {
                // The finish feeds the display name, the slug and the identity, so
                // they all move together; the old slug is kept by the model hook.
                $item->setAttribute('attributes', $attributes);
                $item->slug = $item->buildUniqueSlug();
                $item->forceFill($identity->forItem($item))->save();
            });

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('relabelled '.count($planned).' row(s).');

        return self::SUCCESS;
    }
}
