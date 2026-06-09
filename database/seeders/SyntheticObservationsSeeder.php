<?php

namespace Database\Seeders;

use App\Actions\Valuation\RecomputeCatalogItem;
use App\Enums\Condition;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\SaleObservation;
use App\Models\Set;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demonstration data for the valuation engine while real sold-comp streams
 * (eBay etc.) are unavailable. Generates synthetic sale_observations *anchored
 * to the real TCGCSV market prices* (database/data/chaos-rising-prices.json) so
 * the engine's output resembles reality — scattered around market, recency-
 * weighted over ~90 days, mixed venues, ~5% planted outliers. Thin-market chase
 * cards get few comps (→ low confidence); a handful get PSA 9/10 graded comps.
 *
 * Deterministic (seeded RNG) and idempotent (clears the set's observations
 * first), then recomputes market_values. Swap this source for a real adapter
 * later with no engine change.
 */
class SyntheticObservationsSeeder extends Seeder
{
    private const VENUES = [['tcgplayer', 0.50], ['ebay', 0.35], ['whatnot', 0.15]];

    public function run(): void
    {
        $path = database_path('data/chaos-rising-prices.json');
        $set = Set::where('slug', 'chaos-rising-en')->first();
        if (! $set || ! is_file($path)) {
            return;
        }

        $prices = json_decode((string) file_get_contents($path), true);
        $psaId = GradingCompany::where('slug', 'psa')->value('id');
        $recompute = app(RecomputeCatalogItem::class);
        $now = Carbon::now();

        mt_srand(20260609); // reproducible

        $items = CatalogItem::where('set_id', $set->id)->orderBy('id')->get();
        SaleObservation::whereIn('catalog_item_id', $items->pluck('id'))->delete();

        foreach ($items as $item) {
            $pid = $item->external_ids['tcgplayer_product_id'] ?? null;
            $rows = $prices[(string) $pid] ?? null;
            if (! $rows) {
                continue;
            }

            $variant = $item->attributes['variant'] ?? null;
            $row = collect($rows)->firstWhere('variant', $variant) ?? $rows[0];
            $market = (int) $row['market'];
            if ($market <= 0) {
                continue;
            }

            $sealed = $item->item_type === ItemType::Sealed;
            $batch = [];

            // Raw NM (singles) / SEALED (sealed product).
            $this->makeComps(
                $batch, $item->id,
                condition: $sealed ? Condition::Sealed->value : Condition::NearMint->value,
                companyId: null, grade: null, gradeLabel: null,
                market: $market, low: (int) $row['low'], high: (int) $row['high'],
                n: $this->tierCount($market), now: $now,
            );

            // Graded comps for chase holo singles (market > $50).
            if (! $sealed && $variant === 'holo' && $market > 5000 && $psaId) {
                $this->makeComps($batch, $item->id, condition: null, companyId: $psaId, grade: 10.0,
                    gradeLabel: 'PSA 10', market: (int) round($market * 3.0),
                    low: (int) round($market * 2.2), high: (int) round($market * 5.0),
                    n: mt_rand(3, 6), now: $now);
                $this->makeComps($batch, $item->id, condition: null, companyId: $psaId, grade: 9.0,
                    gradeLabel: 'PSA 9', market: (int) round($market * 1.3),
                    low: (int) round($market * 1.05), high: (int) round($market * 1.8),
                    n: mt_rand(3, 6), now: $now);
            }

            SaleObservation::insert($batch);
            $recompute($item);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function makeComps(array &$batch, int $itemId, ?string $condition, ?int $companyId, ?float $grade, ?string $gradeLabel, int $market, int $low, int $high, int $n, Carbon $now): void
    {
        $sigma = max(0.06, min(0.35, log(max($high, $market) / max($low, 1)) / 4.0));

        for ($i = 0; $i < $n; $i++) {
            $factor = exp($this->gauss($sigma));

            // ~5% planted outliers (a wild ask recorded as sold, or a lowball error).
            if (mt_rand(1, 100) <= 5) {
                $factor *= mt_rand(0, 1) ? mt_rand(280, 450) / 100 : mt_rand(25, 40) / 100;
            }

            $u = mt_rand() / mt_getrandmax();
            $daysAgo = (int) floor($u ** 1.7 * 90); // recency-weighted toward now
            $observedAt = $now->copy()->subDays($daysAgo)->subMinutes(mt_rand(0, 1439));

            $batch[] = [
                'catalog_item_id' => $itemId,
                'condition' => $condition,
                'grading_company_id' => $companyId,
                'grade' => $grade,
                'grade_label' => $gradeLabel,
                'venue' => $this->pickVenue(),
                'price' => max(1, (int) round($market * $factor)),
                'currency' => 'USD',
                'observed_at' => $observedAt->toDateTimeString(),
                'source_listing_id' => "syn-{$itemId}-".($companyId ?? 'raw').($grade ?? '')."-{$i}",
                'is_outlier' => false,
                'raw' => json_encode(['synthetic' => true]),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];
        }
    }

    private function tierCount(int $marketCents): int
    {
        return match (true) {
            $marketCents < 500 => mt_rand(25, 40),
            $marketCents < 5000 => mt_rand(14, 24),
            $marketCents < 20000 => mt_rand(8, 14),
            default => mt_rand(4, 8),
        };
    }

    private function pickVenue(): string
    {
        $r = mt_rand() / mt_getrandmax();
        $cum = 0.0;
        foreach (self::VENUES as [$venue, $weight]) {
            $cum += $weight;
            if ($r <= $cum) {
                return $venue;
            }
        }

        return 'tcgplayer';
    }

    /** Box–Muller normal sample, mean 0. */
    private function gauss(float $sigma): float
    {
        $u1 = max(1e-9, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2) * $sigma;
    }
}
