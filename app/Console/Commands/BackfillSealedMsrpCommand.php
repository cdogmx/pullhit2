<?php

namespace App\Console\Commands;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\SealedMsrpResearcher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Sources + fills missing MSRPs for sealed products via AI web search
 * (SealedMsrpResearcher), storing a citation with each figure. Defaults to the
 * box-type products people actually track for appreciation (booster boxes, ETBs,
 * bundles). Never guesses: products with no credible sourced MSRP are left null.
 * Resumable — a re-run only picks up what's still missing. It also inherits a
 * release date from the parent set for any sealed product lacking one (free).
 */
class BackfillSealedMsrpCommand extends Command
{
    protected $signature = 'sealed:backfill-msrp
        {--type=* : sealed_type(s) to target (default: box-type products)}
        {--line= : product line slug (e.g. pokemon, one-piece, disney-lorcana)}
        {--limit=25 : max products to research this run}
        {--min-confidence=0.5 : store only results at/above this confidence}
        {--force : re-research products that already have an MSRP}
        {--dry-run : research + report, but do not write}';

    protected $description = 'Source + fill missing sealed-product MSRPs via AI web search (cited)';

    /** The high-value sealed types to prioritize when --type isn't given. */
    private const PRIORITY_TYPES = [
        'booster_box',
        'booster_box_case',
        'elite_trainer_box',
        'booster_bundle',
        'build_and_battle',
    ];

    /** msrp_source sentinels marking a researched-but-unfilled product (skip on re-run). */
    private const TRIED_NOT_FOUND = 'not_found';

    private const TRIED_LOW_CONFIDENCE = 'low_confidence';

    public function handle(SealedMsrpResearcher $researcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $minConfidence = (float) $this->option('min-confidence');

        $inherited = $this->inheritReleaseDates($dryRun);
        if ($inherited > 0) {
            $this->line("release dates: {$inherited} sealed product(s) inherited a date from their set".($dryRun ? ' (dry run)' : ''));
        }

        $products = $this->targets();

        if ($products->isEmpty()) {
            $this->info('No sealed products to research (all covered — use --force to refresh, or widen --type/--line).');

            return self::SUCCESS;
        }

        $this->line("researching {$products->count()} product(s)…");

        $filled = 0;
        $notFound = 0;
        $lowConf = 0;

        foreach ($products as $item) {
            $label = $item->name;

            try {
                $result = $researcher->research([
                    'name' => $item->name,
                    'game' => $item->productLine?->name ?? 'trading card game',
                    'type' => $item->getAttribute('attributes')['sealed_type'] ?? 'sealed product',
                    'set' => $item->set?->name,
                    'year' => $item->set?->released_at?->year,
                    'pack_count' => $item->getAttribute('attributes')['pack_count'] ?? null,
                ]);
            } catch (Throwable $e) {
                $this->error("  {$label}: {$e->getMessage()}");

                continue;
            }

            if ($result === null) {
                $notFound++;
                // Record the attempt (msrp stays null) so a batch loop doesn't keep
                // re-picking the same un-findable product. --force re-tries it.
                if (! $dryRun) {
                    $item->forceFill(['msrp_source' => self::TRIED_NOT_FOUND])->save();
                }
                $this->line("  · {$label}: no credible MSRP found — left null");

                continue;
            }

            if ($result['confidence'] < $minConfidence) {
                $lowConf++;
                if (! $dryRun) {
                    $item->forceFill(['msrp_source' => self::TRIED_LOW_CONFIDENCE])->save();
                }
                $this->warn(sprintf('  ? %s: found $%.2f but confidence %.2f < %.2f — skipped', $label, $result['msrp_cents'] / 100, $result['confidence'], $minConfidence));

                continue;
            }

            $filled++;
            $this->info(sprintf('  ✓ %s: $%.2f (conf %.2f) %s', $label, $result['msrp_cents'] / 100, $result['confidence'], $result['source'] ?? ''));

            if (! $dryRun) {
                $item->forceFill([
                    'msrp' => $result['msrp_cents'],
                    'msrp_source' => $result['source'] ?: ($result['note'] ?: 'ai_search'),
                ])->save();
            }
        }

        $this->newLine();
        $this->info("done — filled {$filled}, not found {$notFound}, low-confidence {$lowConf}".($dryRun ? ' (dry run, nothing written)' : ''));

        return self::SUCCESS;
    }

    /**
     * Sealed products still needing an MSRP, matching the type/line filters, boxes
     * first and newest sets first (the ones people track right now). "Still needing"
     * = no MSRP yet AND not already researched (msrp_source unset); --force ignores
     * both so every matching product is re-researched.
     *
     * @return Collection<int, CatalogItem>
     */
    private function targets()
    {
        $types = (array) $this->option('type');
        if ($types === []) {
            $types = self::PRIORITY_TYPES;
        }

        return CatalogItem::query()
            ->where('item_type', ItemType::Sealed->value)
            ->when(! $this->option('force'), fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->whereNull('msrp')->orWhere('msrp', '<=', 0))
                ->whereNull('msrp_source'))
            ->where(function (Builder $q) use ($types) {
                foreach ($types as $type) {
                    $q->orWhere('attributes->sealed_type', $type);
                }
            })
            ->when($this->option('line'), fn (Builder $q, $slug) => $q->whereHas('productLine', fn (Builder $p) => $p->where('slug', $slug)))
            ->with(['productLine:id,name,slug', 'set:id,name,released_at'])
            ->orderByDesc(
                Set::select('released_at')->whereColumn('sets.id', 'catalog_items.set_id'),
            )
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
    }

    /**
     * Give every sealed product missing a release date the one from its parent set
     * (accurate enough — the product ships with its set). Cheap and citation-free.
     */
    private function inheritReleaseDates(bool $dryRun): int
    {
        $query = CatalogItem::query()
            ->where('item_type', ItemType::Sealed->value)
            ->whereNull('released_at')
            ->whereHas('set', fn (Builder $q) => $q->whereNotNull('released_at'))
            ->with('set:id,released_at');

        if ($dryRun) {
            return (clone $query)->count();
        }

        $count = 0;
        $query->chunkById(500, function ($items) use (&$count) {
            foreach ($items as $item) {
                $item->forceFill(['released_at' => $item->set->released_at])->save();
                $count++;
            }
        });

        return $count;
    }
}
