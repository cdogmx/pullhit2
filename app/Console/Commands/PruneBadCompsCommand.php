<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Models\CatalogItem;
use App\Models\SaleObservation;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Re-judges already-stored eBay sale_observations against the CURRENT classifier
 * reject gates and removes the ones that should never have been ingested —
 * multi-card "set" listings, lots, blocklisted titles, wrong printings — then
 * recomputes the affected cards. Use after tightening the classifier so stored
 * values catch up with the live rules. Synthetic placeholders are left alone.
 */
class PruneBadCompsCommand extends Command
{
    protected $signature = 'valuation:prune-bad-comps
        {--card= : only this catalog_item_id}
        {--dry-run : report what would be removed, delete nothing}';

    protected $description = 'Remove stored eBay comps that no longer pass the classifier (multi-card sets, lots, …)';

    public function handle(SoldCompClassifier $classifier, RecomputeCatalogItem $recompute): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $affected = [];
        $removed = 0;
        $checked = 0;

        SaleObservation::query()
            ->where('is_synthetic', false)
            ->whereNotNull('raw->title')
            ->when($this->option('card'), fn (Builder $q, $id) => $q->where('catalog_item_id', $id))
            ->chunkById(500, function ($rows) use ($classifier, $dryRun, &$affected, &$removed, &$checked) {
                $items = CatalogItem::whereIn('id', $rows->pluck('catalog_item_id')->unique())
                    ->get()->keyBy('id');

                foreach ($rows as $o) {
                    $item = $items->get($o->catalog_item_id);
                    $title = $o->raw['title'] ?? null;
                    if (! $item || ! $title) {
                        continue;
                    }

                    $checked++;
                    $candidate = new SoldCandidate($title, (int) $o->price, CarbonImmutable::now(), (string) $o->source_listing_id);

                    if ($classifier->structurallyInvalid($candidate, $item)) {
                        $affected[$o->catalog_item_id] = true;
                        $removed++;
                        if (! $dryRun) {
                            $o->delete();
                        }
                    }
                }
            });

        if (! $dryRun) {
            foreach (array_keys($affected) as $id) {
                if ($card = CatalogItem::find($id)) {
                    ($recompute)($card);
                }
            }
        }

        $verb = $dryRun ? 'would remove' : 'removed';
        $this->info("checked {$checked} comps; {$verb} {$removed} bad comp(s) across ".count($affected).' card(s)'.
            ($dryRun ? ' (dry run — nothing written)' : ', recomputed.'));

        return self::SUCCESS;
    }
}
