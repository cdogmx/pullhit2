<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Support\Catalog\IdentityHash;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Console\Command;

/**
 * Recompute identity_hash + base_key for every catalog_item using the current
 * hashing rules. Run once after changing the hash basis (set-key code → slug) so
 * existing rows line up with what importers now produce — otherwise the next
 * import can't match them and silently duplicates the set. Idempotent.
 */
class RehashCatalogCommand extends Command
{
    protected $signature = 'catalog:rehash {--dry-run : Report how many would change without writing}';

    protected $description = 'Recompute catalog_item identity_hash/base_key with the current rules.';

    public function handle(VerticalRegistry $registry): int
    {
        $dry = (bool) $this->option('dry-run');
        $seen = 0;
        $changed = 0;

        CatalogItem::with(['vertical', 'productLine', 'set'])
            ->chunkById(500, function ($items) use ($registry, $dry, &$seen, &$changed) {
                foreach ($items as $item) {
                    $seen++;
                    $attributes = $item->attributes ?? [];

                    $hashArgs = [
                        'verticalSlug' => $item->vertical->slug,
                        'productLineSlug' => $item->productLine->slug,
                        'setKey' => $item->set?->slug,
                        'itemType' => $item->item_type->value,
                        'name' => $item->name,
                        'number' => $item->number,
                    ];

                    $newHash = IdentityHash::compute(...$hashArgs, attributes: $attributes);

                    $variantKeys = $registry->get($item->vertical->slug)->variantDefiningKeys($item->item_type->value);
                    $baseAttributes = array_diff_key($attributes, array_flip($variantKeys));
                    $newBase = IdentityHash::compute(...$hashArgs, attributes: $baseAttributes);

                    if ($newHash === $item->identity_hash && $newBase === $item->base_key) {
                        continue;
                    }

                    $changed++;
                    if (! $dry) {
                        $item->forceFill(['identity_hash' => $newHash, 'base_key' => $newBase])->save();
                    }
                }
            });

        $this->info("{$seen} items scanned, {$changed} ".($dry ? 'would be rehashed (dry-run)' : 'rehashed').'.');

        return self::SUCCESS;
    }
}
