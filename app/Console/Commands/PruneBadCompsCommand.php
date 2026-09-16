<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Models\CatalogItem;
use App\Models\SaleObservation;
use App\Models\Set;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Output\OutputInterface;

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
        {--set= : only the cards in this set, by slug}
        {--from-id= : only observations above this id}
        {--to-id= : only observations up to this id}
        {--limit= : stop after this many observations, and report where to resume}
        {--dry-run : report what would be removed, delete nothing}';

    protected $description = 'Remove stored eBay comps that no longer pass the classifier (multi-card sets, lots, …)';

    public function handle(SoldCompClassifier $classifier, RecomputeCatalogItem $recompute): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $set = null;

        if ($slug = $this->option('set')) {
            $set = Set::where('slug', $slug)->first();

            if (! $set) {
                $this->error("No set with slug {$slug}.");

                return self::FAILURE;
            }
        }

        $affected = [];
        $removed = 0;
        $checked = 0;
        $limit = (int) $this->option('limit');
        $lastId = (int) $this->option('from-id');

        SaleObservation::query()
            ->where('is_synthetic', false)
            ->whereNotNull('raw->title')
            ->when($this->option('card'), fn (Builder $q, $id) => $q->where('catalog_item_id', $id))
            // Scoped, because the unscoped pass walks every stored comp we hold
            // — 1.28M of them, over a remote connection, which runs for hours.
            // Far too slow to reach for after tightening one thing about one set.
            ->when($set, fn (Builder $q) => $q->whereIn(
                'catalog_item_id',
                CatalogItem::where('set_id', $set->id)->select('id'),
            ))
            // Sliceable by id so the pass can be run in bounded runs and picked
            // up where it stopped. The table holds 1.2M comps over a remote
            // connection; one uninterruptible multi-hour pass is a thing nobody
            // can schedule around, and the reject rate climbs the further back
            // you go — 0% in September, 12.8% in mid-August, 20.7% before that
            // — so the oldest slices are worth doing first.
            ->when($this->option('from-id'), fn (Builder $q, $id) => $q->where('id', '>', (int) $id))
            ->when($this->option('to-id'), fn (Builder $q, $id) => $q->where('id', '<=', (int) $id))
            ->chunkById(500, function ($rows) use ($classifier, $recompute, $dryRun, $limit, &$affected, &$removed, &$checked, &$lastId) {
                $items = CatalogItem::whereIn('id', $rows->pluck('catalog_item_id')->unique())
                    ->get()->keyBy('id');
                $touched = [];

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
                        $touched[$o->catalog_item_id] = $item;
                        $removed++;
                        if (! $dryRun) {
                            $o->delete();
                        }
                    }
                }

                // Recompute the cards this chunk touched, before moving on.
                //
                // This used to run once at the end, which meant the pass was
                // only correct if it ran to completion — and against production
                // it walks 1.28M comps over a remote connection and takes hours.
                // Interrupt it and every card it had already stripped kept a
                // value derived from comps that were no longer there, with
                // nothing to catch them afterwards: valuation:recompute --stale
                // looks for observations NEWER than the value, and a deletion
                // leaves none. Per chunk, stopping early costs only the work not
                // yet done.
                if (! $dryRun) {
                    foreach ($touched as $card) {
                        ($recompute)($card);
                    }
                }

                $lastId = $rows->last()->id ?? $lastId;

                $this->line(sprintf(
                    '  %s checked, %s removed… (id %s)',
                    number_format($checked),
                    number_format($removed),
                    number_format((int) $lastId),
                ), null, OutputInterface::VERBOSITY_VERBOSE);

                // Stop cleanly on the chunk boundary rather than mid-card: the
                // recompute above has already run for everything touched.
                return ! ($limit > 0 && $checked >= $limit);
            });

        if ($limit > 0 && $checked >= $limit) {
            $this->info("Stopped at the limit. Resume with --from-id={$lastId}");
        }

        $verb = $dryRun ? 'would remove' : 'removed';
        $this->info("checked {$checked} comps; {$verb} {$removed} bad comp(s) across ".count($affected).' card(s)'.
            ($dryRun ? ' (dry run — nothing written)' : ', recomputed.'));

        return self::SUCCESS;
    }
}
