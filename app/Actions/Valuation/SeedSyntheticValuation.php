<?php

namespace App\Actions\Valuation;

use App\Enums\Condition;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\SaleObservation;
use Illuminate\Support\Carbon;

/**
 * Generate synthetic sale_observations for one catalog item, anchored to a known
 * market price, then recompute its market_values. Demonstration/estimate data
 * while real sold-comp streams fill in lazily — comps are flagged is_synthetic
 * and the resulting values are is_estimated. Replaces only this item's synthetic
 * comps (real eBay comps are preserved). Deterministic per item.
 */
class SeedSyntheticValuation
{
    private const VENUES = [['tcgplayer', 0.50], ['ebay', 0.35], ['whatnot', 0.15]];

    public function __construct(
        protected RecomputeCatalogItem $recompute,
    ) {}

    /**
     * @param  array<int, array{company: string, grade: float, label: string, cents: int}>  $gradedAnchors
     *         Explicit graded prices (e.g. PriceCharting PSA 10/9/8, BGS 10) — used
     *         in place of the synthetic graded multipliers when provided.
     */
    public function __invoke(CatalogItem $item, int $anchorCents, ?int $lowCents = null, ?int $highCents = null, array $gradedAnchors = []): void
    {
        if ($anchorCents <= 0 && $gradedAnchors === []) {
            return;
        }

        // Deterministic per item so re-imports are stable.
        mt_srand(crc32((string) $item->identity_hash));

        $now = Carbon::now();
        $psaId = GradingCompany::where('slug', 'psa')->value('id');
        $sealed = $item->item_type === ItemType::Sealed;
        $variant = $item->getAttribute('attributes')['variant'] ?? null;

        $batch = [];

        if ($anchorCents > 0) {
            $low = $lowCents && $lowCents > 0 ? $lowCents : (int) round($anchorCents * 0.7);
            $high = $highCents && $highCents > 0 ? $highCents : (int) round($anchorCents * 1.6);

            $this->makeComps(
                $batch, $item->id,
                condition: $sealed ? Condition::Sealed->value : Condition::NearMint->value,
                companyId: null, grade: null, gradeLabel: null,
                market: $anchorCents, low: $low, high: $high,
                n: $this->tierCount($anchorCents), now: $now,
            );
        }

        if ($gradedAnchors !== []) {
            // Real graded prices (PriceCharting) — anchor each tier directly.
            foreach ($gradedAnchors as $g) {
                $cents = (int) ($g['cents'] ?? 0);
                $companyId = $this->companyId((string) ($g['company'] ?? ''));
                if ($cents <= 0 || ! $companyId) {
                    continue;
                }
                $this->makeComps($batch, $item->id, condition: null, companyId: $companyId,
                    grade: (float) $g['grade'], gradeLabel: $g['label'], market: $cents,
                    low: (int) round($cents * 0.8), high: (int) round($cents * 1.35),
                    n: mt_rand(3, 6), now: $now);
            }
        } elseif (! $sealed && $variant === 'holo' && $anchorCents > 5000 && $psaId) {
            // Synthetic graded comps for chase holo singles (> $50) with no source.
            $this->makeComps($batch, $item->id, condition: null, companyId: $psaId, grade: 10.0,
                gradeLabel: 'PSA 10', market: (int) round($anchorCents * 3.0),
                low: (int) round($anchorCents * 2.2), high: (int) round($anchorCents * 5.0),
                n: mt_rand(3, 6), now: $now);
            $this->makeComps($batch, $item->id, condition: null, companyId: $psaId, grade: 9.0,
                gradeLabel: 'PSA 9', market: (int) round($anchorCents * 1.3),
                low: (int) round($anchorCents * 1.05), high: (int) round($anchorCents * 1.8),
                n: mt_rand(3, 6), now: $now);
        }

        // Replace only the synthetic comps; keep any real (eBay) ones.
        $item->saleObservations()->where('is_synthetic', true)->delete();
        SaleObservation::insert($batch);

        ($this->recompute)($item);
    }

    /**
     * @param  array<int, array<string, mixed>>  $batch
     */
    private function makeComps(array &$batch, int $itemId, ?string $condition, ?int $companyId, ?float $grade, ?string $gradeLabel, int $market, int $low, int $high, int $n, Carbon $now): void
    {
        $sigma = max(0.06, min(0.35, log(max($high, $market) / max($low, 1)) / 4.0));

        for ($i = 0; $i < $n; $i++) {
            $factor = exp($this->gauss($sigma));

            if (mt_rand(1, 100) <= 5) {
                $factor *= mt_rand(0, 1) ? mt_rand(280, 450) / 100 : mt_rand(25, 40) / 100;
            }

            $u = mt_rand() / mt_getrandmax();
            $daysAgo = (int) floor($u ** 1.7 * 90);
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
                'is_synthetic' => true,
                'raw' => json_encode(['synthetic' => true]),
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ];
        }
    }

    /** @var array<string, int|null> */
    private array $companyIds = [];

    private function companyId(string $slug): ?int
    {
        return $this->companyIds[$slug] ??= GradingCompany::where('slug', $slug)->value('id');
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

    private function gauss(float $sigma): float
    {
        $u1 = max(1e-9, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2) * $sigma;
    }
}
