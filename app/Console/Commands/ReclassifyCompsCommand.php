<?php

namespace App\Console\Commands;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\SaleObservation;
use App\Models\Set;
use App\Support\Ebay\SoldCandidate;
use App\Support\Ebay\SoldCompClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-reads the priced state of stored comps and moves the misfiled ones.
 *
 * Distinct from valuation:prune-bad-comps, which DELETES listings that should
 * never have been comps at all. These are genuine single-card sales sitting in
 * the wrong band — a real PSA 10 sale counted as a raw card because the title
 * said "PSA GRADED 10" and the grade-resolver only understood "PSA 10".
 * Pruning them would throw away good data: the sale is fine, the filing is wrong.
 *
 * Why this matters more than it looks: the raw median is also the anchor the
 * price-sanity band is measured against, so a slab sitting in the raw band
 * raises the ceiling for the next slab. Left alone it ratchets.
 *
 * Structurally invalid rows are skipped and left for the prune pass, so the two
 * commands never fight over the same row.
 */
class ReclassifyCompsCommand extends Command
{
    protected $signature = 'valuation:reclassify-comps
        {--card= : only this catalog_item_id}
        {--set= : only the cards in this set, by slug}
        {--from-id= : only observations above this id}
        {--to-id= : only observations up to this id}
        {--limit= : stop after this many observations, and report where to resume}
        {--dry-run : report what would move, write nothing}';

    protected $description = 'Re-resolve graded vs raw on stored comps and move the misfiled ones into the right band';

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

        $companyIds = GradingCompany::pluck('id', 'slug')->all();
        $companyNames = GradingCompany::pluck('slug', 'id')->all();

        $affected = [];
        $moved = 0;
        $checked = 0;
        $skipped = 0;
        $sealed = 0;
        /** @var array<string, int> $transitions */
        $transitions = [];
        /** @var array<int, string> $samples */
        $samples = [];
        $limit = (int) $this->option('limit');
        $lastId = (int) $this->option('from-id');

        SaleObservation::query()
            ->where('is_synthetic', false)
            ->whereNotNull('raw->title')
            ->when($this->option('card'), fn (Builder $q, $id) => $q->where('catalog_item_id', $id))
            ->when($set, fn (Builder $q) => $q->whereIn(
                'catalog_item_id',
                CatalogItem::where('set_id', $set->id)->select('id'),
            ))
            ->when($this->option('from-id'), fn (Builder $q, $id) => $q->where('id', '>', (int) $id))
            ->when($this->option('to-id'), fn (Builder $q, $id) => $q->where('id', '<=', (int) $id))
            ->chunkById(500, function ($rows) use (
                $classifier, $recompute, $dryRun, $limit, $companyIds, $companyNames,
                &$affected, &$moved, &$checked, &$skipped, &$sealed, &$transitions, &$samples, &$lastId
            ) {
                // Eager for the same reason the prune pass is: the classifier
                // reads the set and its product line for nearly every comp, and
                // lazily that is two round trips per card over a remote database.
                $items = CatalogItem::whereIn('id', $rows->pluck('catalog_item_id')->unique())
                    ->with(['set', 'productLine'])
                    ->get()->keyBy('id');
                $touched = [];

                foreach ($rows as $o) {
                    $item = $items->get($o->catalog_item_id);
                    $title = $o->raw['title'] ?? null;

                    if (! $item || ! $title) {
                        continue;
                    }

                    $checked++;

                    // Sealed product comps are SEALED whatever the title says;
                    // pricedState() only ever described singles. Running them
                    // through it turns a sealed booster box into "PSA 2".
                    if ($item->item_type === ItemType::Sealed) {
                        $sealed++;

                        continue;
                    }

                    $candidate = new SoldCandidate($title, (int) $o->price, CarbonImmutable::now(), (string) $o->source_listing_id);

                    // Prune's row, not ours. Re-filing something that ought to
                    // be deleted only moves the problem into another band.
                    if ($classifier->structurallyInvalid($candidate, $item)) {
                        $skipped++;

                        continue;
                    }

                    $comp = $classifier->pricedState($candidate, $companyIds);

                    $was = $this->stateLabel($o->grading_company_id, $o->grade, $o->condition, $companyNames);
                    $now = $this->stateLabel($comp->gradingCompanyId, $comp->grade, $comp->condition, $companyNames);

                    if ($was === $now) {
                        continue;
                    }

                    $moved++;
                    $affected[$o->catalog_item_id] = true;
                    $touched[$o->catalog_item_id] = $item;

                    $key = "{$was} -> {$now}";
                    $transitions[$key] = ($transitions[$key] ?? 0) + 1;

                    if (count($samples) < 20) {
                        $samples[] = sprintf(
                            '  %-18s %9s  %s',
                            $key,
                            '$'.number_format((float) $o->price / 100, 2),
                            mb_substr((string) $title, 0, 62),
                        );
                    }

                    if (! $dryRun) {
                        $o->forceFill([
                            'condition' => $comp->condition,
                            'grading_company_id' => $comp->gradingCompanyId,
                            'grade' => $comp->grade,
                            'grade_label' => $comp->gradeLabel,
                        ])->save();
                    }
                }

                // Per chunk, so an interrupted pass never leaves a card holding
                // a value derived from a filing that has already moved.
                if (! $dryRun) {
                    foreach ($touched as $card) {
                        ($recompute)($card);
                    }
                }

                $lastId = $rows->last()->id ?? $lastId;

                $this->line(sprintf(
                    '  %s checked, %s moved… (id %s)',
                    number_format($checked),
                    number_format($moved),
                    number_format((int) $lastId),
                ), null, OutputInterface::VERBOSITY_VERBOSE);

                return ! ($limit > 0 && $checked >= $limit);
            });

        if ($limit > 0 && $checked >= $limit) {
            $this->info("Stopped at the limit. Resume with --from-id={$lastId}");
        }

        if ($samples !== []) {
            $this->newLine();
            $this->line('Sample of what moves:');

            foreach ($samples as $line) {
                $this->line($line);
            }
        }

        if ($transitions !== []) {
            $this->newLine();
            arsort($transitions);
            $this->table(['transition', 'comps'], array_map(
                fn ($k, $v) => [$k, number_format($v)],
                array_keys($transitions),
                $transitions,
            ));
        }

        $verb = $dryRun ? 'would move' : 'moved';
        $this->newLine();
        $this->info("checked {$checked} comps; {$verb} {$moved} across ".count($affected).' card(s)'.
            "; left {$skipped} structurally invalid row(s) to the prune pass".
            " and passed over {$sealed} sealed comp(s)".
            ($dryRun ? ' (dry run — nothing written)' : ', recomputed.'));

        return self::SUCCESS;
    }

    /**
     * The band a comp sits in, as one short string — "PSA 10" or "NM".
     *
     * Comparing the rendered band rather than the four columns keeps the pass
     * from rewriting rows that differ only in how a grade was spelled.
     *
     * @param  array<int, string>  $companyNames
     */
    private function stateLabel(?int $companyId, mixed $grade, mixed $condition, array $companyNames): string
    {
        if ($companyId !== null) {
            $name = strtoupper($companyNames[$companyId] ?? (string) $companyId);
            $g = rtrim(rtrim(sprintf('%.1f', (float) $grade), '0'), '.');

            return "{$name} {$g}";
        }

        return (string) ($condition?->value ?? $condition ?? 'NM');
    }
}
