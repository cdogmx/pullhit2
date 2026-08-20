<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\TcgcsvClient;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Fill in the descriptive facts a price-only source never supplied — rarity and
 * card type — from TCGCSV, for cards that already exist.
 *
 * One Piece arrived through PriceCharting, which prices cards but does not
 * describe them: 11,205 of its 11,811 rows carry no rarity and none carry a
 * type, so a typical card is stored as name, number, variant and nothing else.
 * TCGplayer publishes all of it (Rarity, Color, CardType, Cost, Power…), and our
 * collector numbers already agree with theirs — 119 of 120 matched on the first
 * set checked.
 *
 * This UPDATES existing rows and never inserts. That distinction matters: an
 * import against these sets would key new rows off the source's own idea of a
 * printing and duplicate the catalog. Rarity and type are descriptive rather
 * than identity-defining (see ItemIdentity), so writing them cannot move an
 * identity_hash, a base_key, a display name or a URL.
 *
 * Existing values are left alone unless --overwrite is passed, so anything
 * curated by hand survives a re-run.
 */
class BackfillCardFactsCommand extends Command
{
    protected $signature = 'catalog:backfill-card-facts
        {--line=one-piece : product line slug (must be a TCGCSV-supported game)}
        {--set= : limit to one set slug}
        {--overwrite : replace facts we already hold, not just fill blanks}
        {--execute : apply the changes (otherwise report only)}';

    protected $description = 'Fill rarity and card type from TCGCSV for cards that already exist';

    public function handle(TcgcsvClient $client): int
    {
        @ini_set('memory_limit', '1024M');

        try {
            $game = TcgcsvGame::fromSlug((string) $this->option('line'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $line = ProductLine::where('slug', $game->value)->first();

        if (! $line) {
            $this->error("No product line [{$game->value}] in the catalog.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $overwrite = (bool) $this->option('overwrite');

        $this->line($execute ? '<comment>EXECUTING</comment>' : '<info>DRY RUN</info> — pass --execute to apply');
        $this->line("line: {$line->name}   category: {$game->categoryId()}"
            .($overwrite ? '   (overwriting existing facts)' : ''));

        $groups = collect($client->groups($game->categoryId()));
        $this->line('upstream groups: '.$groups->count());

        $sets = Set::where('product_line_id', $line->id)
            ->when($this->option('set'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        [$matched, $unmatched] = $this->matchSets($sets, $groups);

        $this->line('sets: '.$sets->count().' — matched '.count($matched).', unmatched '.count($unmatched));

        $totals = ['rarity' => 0, 'type' => 0, 'rows' => 0, 'linked' => 0, 'missed' => 0];
        $bar = $this->output->createProgressBar(count($matched));

        foreach ($matched as ['set' => $set, 'group' => $group]) {
            $bar->advance();

            try {
                $facts = $this->factsFor($client, $game, (int) $group['groupId']);
            } catch (Throwable $e) {
                $this->newLine();
                $this->warn("  {$set->name}: could not read group {$group['groupId']} — {$e->getMessage()}");

                continue;
            }

            $applied = $this->applyToSet($set, $facts, $overwrite, $execute);

            $totals['rarity'] += $applied['rarity'];
            $totals['type'] += $applied['type'];
            $totals['rows'] += $applied['rows'];
            $totals['missed'] += $applied['missed'];

            // Remember the link so the next run (and any importer) skips the
            // name matching entirely.
            if ($execute && empty($set->external_ids['tcgplayer_group_id'])) {
                $set->forceFill([
                    'external_ids' => array_merge((array) ($set->external_ids ?? []), [
                        'tcgplayer_group_id' => (string) $group['groupId'],
                    ]),
                ])->save();
                $totals['linked']++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('rows updated       '.number_format($totals['rows']));
        $this->line('   rarity filled   '.number_format($totals['rarity']));
        $this->line('   type filled     '.number_format($totals['type']));
        $this->line('cards with no upstream match: '.number_format($totals['missed']));

        if ($execute) {
            $this->line('sets newly linked to a TCGplayer group: '.number_format($totals['linked']));
        }

        if ($unmatched !== []) {
            $this->newLine();
            $this->warn('sets with no upstream group (names differ — link by hand or rename):');
            foreach (array_slice($unmatched, 0, 15) as $set) {
                $this->line('   '.str_pad(substr($set->name, 0, 44), 46)
                    .str_pad((string) ($set->code ?: '—'), 8).$set->language);
            }
            if (count($unmatched) > 15) {
                $this->line('   … '.(count($unmatched) - 15).' more');
            }
        }

        if (! $execute) {
            $this->newLine();
            $this->info('Nothing written.');
        }

        return self::SUCCESS;
    }

    /**
     * Pair our sets with upstream groups. An already-recorded group id wins;
     * otherwise the set name, then its code against the group's abbreviation.
     *
     * @param  Collection<int, Set>  $sets
     * @param  Collection<int, array<string, mixed>>  $groups
     * @return array{0: array<int, array{set: Set, group: array<string, mixed>}>, 1: array<int, Set>}
     */
    protected function matchSets(Collection $sets, Collection $groups): array
    {
        $byId = $groups->keyBy(fn ($g) => (string) $g['groupId']);
        $byName = $groups->keyBy(fn ($g) => Str::slug((string) ($g['name'] ?? '')));
        // Codes are punctuated inconsistently between the two catalogues — we
        // store "ST15" where TCGplayer writes "ST-15" — so compare them stripped.
        $byCode = $groups->filter(fn ($g) => ! empty($g['abbreviation']))
            ->keyBy(fn ($g) => $this->codeKey((string) $g['abbreviation']));

        $matched = [];
        $unmatched = [];

        foreach ($sets as $set) {
            $recorded = Arr::get((array) $set->external_ids, 'tcgplayer_group_id');

            $group = ($recorded && $byId->has((string) $recorded)) ? $byId->get((string) $recorded) : null;
            $group ??= $byName->get(Str::slug($set->name));
            $group ??= $set->code ? $byCode->get($this->codeKey($set->code)) : null;

            if ($group) {
                $matched[] = ['set' => $set, 'group' => $group];
            } else {
                $unmatched[] = $set;
            }
        }

        return [$matched, $unmatched];
    }

    /**
     * collector number => the facts upstream states for it.
     *
     * A number can appear twice upstream — a card and its "(Parallel)" printing
     * share one — but they describe the same card, so the first wins.
     *
     * @return array<string, array{rarity: ?string, type: ?string}>
     */
    protected function factsFor(TcgcsvClient $client, TcgcsvGame $game, int $groupId): array
    {
        $typeField = $game->typeField();
        $facts = [];

        foreach ($client->products($groupId, $game->categoryId()) as $product) {
            $extended = collect($product['extendedData'] ?? [])->keyBy('name');
            $number = $this->normalize((string) Arr::get($extended->get('Number', []), 'value'));

            if ($number === '' || isset($facts[$number])) {
                continue;
            }

            $facts[$number] = [
                'rarity' => $this->clean(Arr::get($extended->get('Rarity', []), 'value')),
                'type' => $typeField
                    ? $this->clean(Arr::get($extended->get($typeField, []), 'value'))
                    : null,
            ];
        }

        return $facts;
    }

    /**
     * @param  array<string, array{rarity: ?string, type: ?string}>  $facts
     * @return array{rarity: int, type: int, rows: int, missed: int}
     */
    protected function applyToSet(Set $set, array $facts, bool $overwrite, bool $execute): array
    {
        $counts = ['rarity' => 0, 'type' => 0, 'rows' => 0, 'missed' => 0];

        $items = CatalogItem::where('set_id', $set->id)
            ->where('item_type', 'single')
            ->whereNotNull('number')
            ->where('number', '!=', '')
            ->get();

        foreach ($items as $item) {
            $fact = $facts[$this->normalize((string) $item->number)] ?? null;

            if ($fact === null) {
                $counts['missed']++;

                continue;
            }

            $attributes = $item->getAttribute('attributes') ?? [];
            $changed = false;

            foreach (['rarity', 'type'] as $key) {
                if ($fact[$key] === null) {
                    continue;
                }
                if (! $overwrite && ! empty($attributes[$key])) {
                    continue;
                }
                if (($attributes[$key] ?? null) === $fact[$key]) {
                    continue;
                }

                $attributes[$key] = $fact[$key];
                $counts[$key]++;
                $changed = true;
            }

            if (! $changed) {
                continue;
            }

            $counts['rows']++;

            if ($execute) {
                // A plain attribute write: rarity and type are descriptive, so no
                // identity, base key, display name or slug moves with them.
                DB::table('catalog_items')->where('id', $item->id)->update([
                    'attributes' => json_encode($attributes),
                    'updated_at' => now(),
                ]);
            }
        }

        return $counts;
    }

    /** Set codes compare without punctuation or case: "ST-15" === "ST15". */
    protected function codeKey(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** Collector numbers compare case- and padding-insensitively. */
    protected function normalize(string $number): string
    {
        return strtoupper(trim($number));
    }

    protected function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
