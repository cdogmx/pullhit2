<?php

namespace App\Console\Commands;

use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use Illuminate\Console\Command;

/**
 * Generate a foil-variant entry for each non-foil single in a product line
 * (Lorcana). The foil is a distinct printing (its own identity_hash) that groups
 * under the same base card, reuses its art, and is seeded with an estimated value
 * (non-foil × multiplier) until real foil sold comps price it on view. Idempotent.
 */
class GenerateFoilsCommand extends Command
{
    protected $signature = 'catalog:generate-foils
        {--line=lorcana : product line slug}
        {--multiplier= : foil premium for the seed estimate (default from config)}
        {--limit= : cap how many base cards to process}
        {--dry : report counts without writing}';

    protected $description = 'Generate foil variant entries for a product line\'s singles';

    public function handle(CreateCatalogItem $create, SeedSyntheticValuation $seed): int
    {
        @ini_set('memory_limit', '1024M');

        $line = ProductLine::where('slug', $this->option('line'))->first();
        if (! $line) {
            $this->error("No product line [{$this->option('line')}].");

            return self::FAILURE;
        }

        $mult = (float) ($this->option('multiplier') ?: config('valuation.lorcana_foil_multiplier', 2.0));
        $dry = (bool) $this->option('dry');

        $created = 0;
        $seeded = 0;
        $skipped = 0;

        CatalogItem::query()
            ->where('product_line_id', $line->id)
            ->where('item_type', ItemType::Single)
            ->where('attributes->variant', 'normal')
            ->when($this->option('limit'), fn ($q, $n) => $q->limit((int) $n))
            ->with(['vertical', 'productLine', 'set', 'defaultMarketValue'])
            ->chunkById(200, function ($chunk) use ($create, $seed, $mult, $dry, &$created, &$seeded, &$skipped) {
                foreach ($chunk as $base) {
                    // Enchanted/Iconic alt-arts are already their own (foil) cards.
                    if (($base->getAttribute('attributes')['rarity'] ?? null) === 'Enchanted') {
                        $skipped++;

                        continue;
                    }

                    if ($dry) {
                        $created++;

                        continue;
                    }

                    $attrs = $base->getAttribute('attributes') ?? [];
                    $attrs['variant'] = 'foil';

                    // Drop the non-foil card's pricing ids so the foil doesn't
                    // inherit its PriceCharting/TCGplayer product (wrong prices).
                    $ext = $base->external_ids ?? [];
                    unset($ext['pricecharting_id'], $ext['tcgplayer_product_id']);

                    $foil = $create(
                        $base->vertical,
                        $base->productLine,
                        $base->set,
                        ItemType::Single,
                        $base->name,
                        $base->number,
                        $attrs,
                        $ext,
                        $base->primary_image_path,
                    );

                    if (! $foil->wasRecentlyCreated) {
                        $skipped++;

                        continue;
                    }

                    $created++;

                    // Seed an estimate off the non-foil value; real comps replace it.
                    $anchor = (int) ($base->defaultMarketValue?->median ?? 0);
                    if ($anchor > 0) {
                        $seed($foil, (int) round($anchor * $mult));
                        $seeded++;
                    }
                }
            });

        $verb = $dry ? 'would create' : 'created';
        $this->info("{$line->name}: {$verb} {$created} foil(s), seeded {$seeded}, skipped {$skipped}.");

        return self::SUCCESS;
    }
}
