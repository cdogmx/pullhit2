<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\CatalogItemSlugAlias;
use App\Models\ProductLine;
use App\Support\Ebay\SoldCompClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fold the generated foil twin of a foil-only Lorcana card back into the card.
 *
 * Enchanted, Epic and Iconic are printed only in foil. catalog:generate-foils
 * skipped Enchanted alone, so every Epic and Iconic got a phantom "foil" twin
 * beside it, and the Lorcana foil gate sent each sale that said "foil" to the
 * twin. The Hyperia City Iconic Mickey #241 read as two printings with its 45
 * sales split 38 / 7.
 *
 * The non-foil row is kept: it holds the URL people link to and the TCGplayer
 * product. The twin's real sales and listings move to it (a sale both rows
 * ingested is kept once), its synthetic seeds and derived values go, anything a
 * person made is re-pointed, and its slug becomes an alias so its URL still
 * lands on the card.
 */
class MergeFoilOnlyTwinsCommand extends Command
{
    protected $signature = 'catalog:merge-foil-only-twins
        {--line=lorcana : product line slug}
        {--dry-run : report what would merge, write nothing}';

    protected $description = 'Merge generated foil twins of foil-only Lorcana rarities into the card';

    /** Rows a person made, or that point at the card on purpose — re-pointed, never dropped. */
    private const REPOINT = [
        'collection_items' => 'catalog_item_id',
        'wishlist_items' => 'catalog_item_id',
        'marketplace_listings' => 'catalog_item_id',
        'tracked_products' => 'catalog_item_id',
        'item_edit_suggestions' => 'catalog_item_id',
        'comp_adjudications' => 'catalog_item_id',
        'ebay_sweep_overrides' => 'catalog_item_id',
        'scan_feedback' => 'corrected_catalog_item_id',
    ];

    /** Observations: moved to the card unless the card already has the same listing. */
    private const OBSERVATIONS = ['sale_observations', 'listing_observations'];

    public function handle(RecomputeCatalogItem $recompute): int
    {
        $dry = (bool) $this->option('dry-run');
        $line = ProductLine::where('slug', $this->option('line'))->firstOrFail();

        $twins = CatalogItem::query()
            ->where('product_line_id', $line->id)
            ->where('item_type', ItemType::Single)
            ->where('variant', 'foil')
            ->whereIn('rarity', SoldCompClassifier::LORCANA_FOIL_ONLY)
            ->with('set')
            ->get();

        $merged = 0;

        foreach ($twins as $twin) {
            $card = CatalogItem::query()
                ->where('set_id', $twin->set_id)
                ->where('item_type', ItemType::Single)
                ->where('name', $twin->name)
                ->where('number', $twin->number)
                ->where('variant', 'normal')
                ->first();

            if (! $card) {
                $this->warn("no card for foil twin {$twin->id} {$twin->name} #{$twin->number} — left alone");

                continue;
            }

            $real = DB::table('sale_observations')->where('catalog_item_id', $twin->id)->where('is_synthetic', false)->count();
            $this->line(sprintf('%s %s #%s [%s]: twin %d (%d real sales) into %d',
                $dry ? 'would merge' : 'merged', $twin->name, $twin->number, $twin->set?->name, $twin->id, $real, $card->id));
            $merged++;

            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($twin, $card) {
                DB::table('sale_observations')->where('catalog_item_id', $twin->id)->where('is_synthetic', true)->delete();

                foreach (self::OBSERVATIONS as $table) {
                    $held = DB::table($table)->where('catalog_item_id', $card->id)
                        ->whereNotNull('source_listing_id')->pluck('source_listing_id')->all();

                    DB::table($table)->where('catalog_item_id', $twin->id)
                        ->whereIn('source_listing_id', $held)->delete();
                    DB::table($table)->where('catalog_item_id', $twin->id)
                        ->update(['catalog_item_id' => $card->id]);
                }

                foreach (self::REPOINT as $table => $column) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->where($column, $twin->id)->update([$column => $card->id]);
                    }
                }

                CatalogItemSlugAlias::firstOrCreate(
                    ['set_id' => $twin->set_id, 'slug' => $twin->slug],
                    ['catalog_item_id' => $card->id],
                );

                // Everything left is derived from the twin's own comps — values,
                // snapshots, history, queued scrapes — and goes with it.
                $twin->delete();
            });

            $recompute($card->fresh());
        }

        $this->info(sprintf('%s %d foil twin(s).%s', $dry ? 'would merge' : 'merged', $merged, $dry ? ' Dry run — nothing written.' : ''));

        return self::SUCCESS;
    }
}
