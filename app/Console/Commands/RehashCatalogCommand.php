<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Support\Catalog\ItemIdentity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recomputes every catalog_item's identity_hash and base_key under the current
 * rules, and merges the rows that collapse together as a result.
 *
 * Needed because identity_hash used to be a function of EVERY attribute, so the
 * same printing hashed differently depending on how much its source knew (see
 * ItemIdentity). Rows already in the catalog carry the old hashes; until they are
 * recomputed, an importer computing a new-style hash finds no match and inserts a
 * duplicate. Also strips the " - 003/084" collector number some importers left in
 * the card name, since that feeds the hash too.
 *
 * Dry by default. Nothing is written without --execute.
 */
class RehashCatalogCommand extends Command
{
    protected $signature = 'catalog:rehash
        {--execute : apply the changes (otherwise report only)}
        {--set= : limit to one set id}
        {--skip-names : leave card names alone, recompute hashes only}';

    protected $description = 'Recompute identity_hash/base_key, clean collector numbers out of names, and merge rows that collapse';

    /** Tables carrying a catalog_item_id that must follow a merged row. */
    protected const DEPENDENTS = [
        'market_values', 'sale_observations', 'value_snapshots', 'listing_observations',
        'scan_fingerprints', 'item_edit_suggestions', 'reconciliation_changes',
        'ebay_sweep_overrides', 'collection_items', 'wishlist_items',
    ];

    /** Rows a real person created — merged, never dropped. */
    protected const USER_DATA = ['collection_items', 'wishlist_items'];

    public function handle(ItemIdentity $identity): int
    {
        @ini_set('memory_limit', '2048M');

        $execute = (bool) $this->option('execute');
        $this->line($execute ? '<comment>EXECUTING</comment>' : '<info>DRY RUN</info> — pass --execute to apply');

        $renamed = 0;
        $rehashed = 0;
        $merged = 0;
        $groups = [];

        // Pass 1: compute the target identity for every row, grouped by it.
        CatalogItem::query()
            ->when($this->option('set'), fn ($q, $id) => $q->where('set_id', $id))
            ->with(['vertical:id,slug', 'productLine:id,slug', 'set:id,slug'])
            ->chunkById(500, function ($items) use ($identity, &$groups, &$renamed) {
                foreach ($items as $item) {
                    $name = $this->option('skip-names')
                        ? $item->name
                        : self::cleanName($item->name);
                    if ($name !== $item->name) {
                        $renamed++;
                    }

                    $keys = $identity->forItem($item, $name);

                    $groups[$keys['identity_hash']][] = [
                        'id' => $item->id,
                        'name' => $name,
                        'base_key' => $keys['base_key'],
                        'attrs' => count(array_filter($item->getAttribute('attributes') ?? [], fn ($v) => $v !== null)),
                        // Current values, so an unchanged row can be skipped rather
                        // than re-written — most of the catalog is already correct.
                        'was' => [
                            'name' => $item->name,
                            'identity_hash' => $item->identity_hash,
                            'base_key' => $item->base_key,
                        ],
                    ];
                }
            });

        $collisions = array_filter($groups, fn ($rows) => count($rows) > 1);
        $absorbed = array_sum(array_map(fn ($rows) => count($rows) - 1, $collisions));

        // How many survivors actually need a write?
        $dirty = 0;
        foreach ($groups as $hash => $rows) {
            $row = $this->survivingRow($rows);
            if ($row['was']['identity_hash'] !== $hash
                || $row['was']['base_key'] !== $row['base_key']
                || $row['was']['name'] !== $row['name']) {
                $dirty++;
            }
        }

        $this->newLine();
        $this->line('rows examined         '.(count($groups) + $absorbed));
        $this->line('names to clean        '.$renamed);
        $this->line('rows needing new keys '.$dirty);
        $this->line('identity collisions   '.count($collisions).' groups, '.$absorbed.' rows absorbed');

        if ($collisions !== []) {
            $this->newLine();
            $this->line('sample merges:');
            foreach (array_slice($collisions, 0, 5, true) as $rows) {
                $ids = implode(', ', array_column($rows, 'id'));
                $this->line("   {$rows[0]['name']}: keeping ".$this->survivorOf($rows)." of [{$ids}]");
            }
        }

        // What user data rides on rows that would be absorbed?
        $absorbedIds = [];
        foreach ($collisions as $rows) {
            $keep = $this->survivorOf($rows);
            foreach ($rows as $row) {
                if ($row['id'] !== $keep) {
                    $absorbedIds[] = $row['id'];
                }
            }
        }
        foreach (self::USER_DATA as $table) {
            if (! Schema::hasTable($table) || $absorbedIds === []) {
                continue;
            }
            $n = DB::table($table)->whereIn('catalog_item_id', $absorbedIds)->count();
            if ($n > 0) {
                $this->line("   {$table}: {$n} rows will be re-pointed to the survivor");
            }
        }

        if (! $execute) {
            $this->newLine();
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        // Pass 2: merge collisions, then write the new keys. Order matters — the
        // absorbed rows must be gone before the survivor takes the shared hash,
        // since identity_hash is unique.
        foreach ($collisions as $hash => $rows) {
            $keep = $this->survivorOf($rows);
            $drop = array_values(array_diff(array_column($rows, 'id'), [$keep]));

            DB::transaction(function () use ($keep, $drop) {
                $this->mergeExternalIds($keep, $drop);
                $this->repoint($keep, $drop);
                CatalogItem::whereIn('id', $drop)->delete();
            });
            $merged += count($drop);
        }

        // Pass 3: write names and keys. Collisions are resolved, so each remaining
        // row owns its hash outright.
        $bar = $this->output->createProgressBar(count($groups));
        foreach ($groups as $hash => $rows) {
            $bar->advance();
            $row = $this->survivingRow($rows);

            if ($row['was']['identity_hash'] === $hash
                && $row['was']['base_key'] === $row['base_key']
                && $row['was']['name'] === $row['name']) {
                continue;
            }

            DB::table('catalog_items')->where('id', $row['id'])->update([
                'name' => $row['name'],
                'identity_hash' => $hash,
                'base_key' => $row['base_key'],
            ]);
            $rehashed++;
        }
        $bar->finish();

        $this->newLine();
        $this->info("merged {$merged} rows, rehashed {$rehashed}, renamed {$renamed}");

        return self::SUCCESS;
    }

    /**
     * Keep the row that knows the most — the richest attribute set, oldest id as
     * the tie-break, so a re-run picks the same survivor.
     *
     * @param  array<int, array{id: int, attrs: int}>  $rows
     */
    protected function survivorOf(array $rows): int
    {
        return $this->survivingRow($rows)['id'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    protected function survivingRow(array $rows): array
    {
        usort($rows, fn ($a, $b) => [$b['attrs'], -$a['id']] <=> [$a['attrs'], -$b['id']]);

        return $rows[0];
    }

    /**
     * @param  array<int, int>  $drop
     */
    protected function mergeExternalIds(int $keep, array $drop): void
    {
        $survivor = CatalogItem::find($keep);
        $ids = $survivor->external_ids ?? [];

        foreach (CatalogItem::whereIn('id', $drop)->get() as $item) {
            foreach ($item->external_ids ?? [] as $key => $value) {
                // First writer wins, so the survivor's own ids are never clobbered.
                $ids[$key] ??= $value;
            }
        }

        // An absorbed row may hold the only image we ever fetched.
        $image = $survivor->primary_image_path
            ?: CatalogItem::whereIn('id', $drop)->whereNotNull('primary_image_path')->value('primary_image_path');

        $survivor->forceFill([
            'external_ids' => $ids === [] ? null : $ids,
            'primary_image_path' => $image,
        ])->save();
    }

    /**
     * @param  array<int, int>  $drop
     */
    protected function repoint(int $keep, array $drop): void
    {
        foreach (self::DEPENDENTS as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'catalog_item_id')) {
                continue;
            }

            if (in_array($table, self::USER_DATA, true)) {
                // Never dropped: a collection or wishlist row follows the survivor.
                DB::table($table)->whereIn('catalog_item_id', $drop)
                    ->update(['catalog_item_id' => $keep]);

                continue;
            }

            // Derived data. The survivor already has its own; the absorbed rows'
            // copies would only double-count comps, so they go.
            DB::table($table)->whereIn('catalog_item_id', $drop)->delete();
        }
    }

    /**
     * Strip the " - 003/084" collector number some importers stored as part of the
     * card name, whether it sits at the end or before a printing parenthetical.
     * Mirrors ImportTcgcsvSet::cleanName.
     */
    public static function cleanName(string $name): string
    {
        $stripped = preg_replace(
            '/\s*-\s*[0-9A-Za-z]+\/[0-9A-Za-z]+(?=\s*\(|\s*$)/',
            '',
            $name,
        );

        return trim((string) $stripped) ?: $name;
    }
}
