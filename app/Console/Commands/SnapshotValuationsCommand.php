<?php

namespace App\Console\Commands;

use App\Actions\Collection\BuildPortfolio;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily value + portfolio snapshots (the price-history seam).
 *
 *  - value_snapshots: today's headline ungraded median for every eligible card
 *    (higher-rarity/valued, or owned/wishlisted).
 *  - portfolio_snapshots: today's value + cost basis for each user that holds
 *    cards (via BuildPortfolio).
 *  - --backfill: seed value_snapshots from real (non-synthetic) sale_observations
 *    bucketed to a daily median. Never uses synthetic rows.
 *
 * Idempotent: a re-run replaces the day's rows.
 */
class SnapshotValuationsCommand extends Command
{
    protected $signature = 'valuation:snapshot {--backfill : Also seed history from real past sale observations}';

    protected $description = 'Capture daily card value + portfolio snapshots';

    public function handle(BuildPortfolio $build): int
    {
        $today = Carbon::now()->toDateString();

        $cards = $this->snapshotValues($today);
        $this->info("value_snapshots: {$cards} eligible cards captured for {$today}.");

        $users = $this->snapshotPortfolios($build, $today);
        $this->info("portfolio_snapshots: {$users} users captured for {$today}.");

        if ($this->option('backfill')) {
            $rows = $this->backfillFromObservations();
            $this->info("backfill: {$rows} historical day-rows seeded from real sales.");
        }

        return self::SUCCESS;
    }

    /** Insert today's headline value for every eligible card (one query). */
    private function snapshotValues(string $today): int
    {
        DB::table('value_snapshots')->where('captured_on', $today)->delete();

        $select = DB::table('market_values as mv')
            ->join('catalog_items as ci', 'ci.id', '=', 'mv.catalog_item_id')
            ->whereNull('mv.grading_company_id')
            ->whereIn('mv.state_key', ['NM', 'SEALED'])
            ->where(fn (Builder $q) => $this->eligibility($q))
            ->selectRaw(
                'mv.catalog_item_id, mv.state_key, mv.median, mv.n_sales, mv.confidence, mv.is_estimated, ?, NOW(), NOW()',
                [$today],
            );

        DB::table('value_snapshots')->insertUsing(
            ['catalog_item_id', 'state_key', 'median_cents', 'n_sales', 'confidence', 'is_estimated', 'captured_on', 'created_at', 'updated_at'],
            $select,
        );

        return DB::table('value_snapshots')->where('captured_on', $today)->count();
    }

    /**
     * Eligible = (real signal AND value ≥ min AND not a skip rarity) OR owned OR
     * wishlisted. Null rarity counts as not-skipped.
     */
    private function eligibility(Builder $q): void
    {
        $minValue = (int) config('valuation.snapshot.min_value', 300);
        $skip = (array) config('valuation.snapshot.skip_rarities', []);

        $q->where(function (Builder $w) use ($minValue, $skip) {
            $w->where('mv.is_estimated', false)->where('mv.median', '>=', $minValue);

            if ($skip !== []) {
                $w->whereNotIn(
                    DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(ci.attributes, '$.rarity')), '')"),
                    $skip,
                );
            }
        })
            ->orWhereExists(fn (Builder $e) => $e->select(DB::raw(1))
                ->from('collection_items')
                ->whereColumn('collection_items.catalog_item_id', 'ci.id'))
            ->orWhereExists(fn (Builder $e) => $e->select(DB::raw(1))
                ->from('wishlist_items')
                ->whereColumn('wishlist_items.catalog_item_id', 'ci.id'));
    }

    /** One row per user that holds cards, via the shared portfolio builder. */
    private function snapshotPortfolios(BuildPortfolio $build, string $today): int
    {
        DB::table('portfolio_snapshots')->where('captured_on', $today)->delete();
        $count = 0;

        User::whereHas('collectionItems')->cursor()->each(function (User $user) use ($build, $today, &$count) {
            $summary = $build($user, null)['summary'];

            DB::table('portfolio_snapshots')->insert([
                'user_id' => $user->id,
                'total_value_cents' => (int) $summary['total_value'],
                'cost_basis_cents' => (int) $summary['total_cost'],
                'card_count' => (int) $summary['card_count'],
                'captured_on' => $today,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $count++;
        });

        return $count;
    }

    /**
     * Seed the headline ('NM') series from real ungraded sale observations,
     * bucketed to a per-day median. Existing rows for a (card, day) are kept.
     */
    private function backfillFromObservations(): int
    {
        // Per (card, date) prices for eligible cards' real ungraded sales.
        $buckets = [];

        DB::table('sale_observations as so')
            ->join('market_values as mv', function ($j) {
                $j->on('mv.catalog_item_id', '=', 'so.catalog_item_id')
                    ->whereNull('mv.grading_company_id')
                    ->whereIn('mv.state_key', ['NM', 'SEALED']);
            })
            ->join('catalog_items as ci', 'ci.id', '=', 'so.catalog_item_id')
            ->where('so.is_synthetic', false)
            ->whereNull('so.grading_company_id')
            ->where(fn (Builder $q) => $this->eligibility($q))
            ->selectRaw('so.catalog_item_id, DATE(so.observed_at) as d, so.price')
            ->orderBy('so.catalog_item_id')
            ->cursor()
            ->each(function (object $r) use (&$buckets) {
                $buckets["{$r->catalog_item_id}|{$r->d}"][] = (int) $r->price;
            });

        $rows = 0;
        foreach (array_chunk($buckets, 500, true) as $chunk) {
            $batch = [];
            foreach ($chunk as $key => $prices) {
                [$cardId, $day] = explode('|', $key);
                $batch[] = [
                    'catalog_item_id' => (int) $cardId,
                    'state_key' => 'NM',
                    'median_cents' => $this->median($prices),
                    'n_sales' => count($prices),
                    'confidence' => null,
                    'is_estimated' => false,
                    'captured_on' => $day,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Ignore (card, state, day) rows that already exist.
            DB::table('value_snapshots')->insertOrIgnore($batch);
            $rows += count($batch);
        }

        return $rows;
    }

    /** @param  array<int, int>  $values */
    private function median(array $values): int
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 ? $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
