<?php

namespace App\Console\Commands;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-seed synthetic comps whose anchor no longer resembles the card's market.
 *
 * Synthetic comps are drawn around one anchor taken at import. When the import
 * ran before release, TCGplayer had no market price yet and the anchor came from
 * a few pre-order asks: the 30th Celebration Classic Collection Metagross was
 * seeded around $410 and read $368 raw / $533 PSA 9 for a $4 card. Synthetic
 * comps sit in the same pool as real ones, so the wrong anchor drags the value
 * even after real sales arrive.
 *
 * Compares each card's synthetic raw median with TCGplayer's CURRENT market price
 * and re-seeds the ones --min-ratio or more ABOVE it (never raises). Graded synthetic
 * tiers are re-anchored on the card's PriceCharting graded prices where we hold
 * them (frozen at the lapsed subscription's last sync, but post-release), and
 * otherwise follow the seeder's own rules. Real comps are never touched.
 */
class ReanchorSyntheticCommand extends Command
{
    protected $signature = 'valuation:reanchor-synthetic
        {--set= : only the cards in this set, by slug}
        {--min-ratio=3 : re-seed when the synthetic median is this many times the market}
        {--dry-run : report what would change, write nothing}';

    protected $description = 'Re-seed synthetic comps anchored far from the card\'s current TCGplayer market price';

    /** Our variant → the TCGplayer subtypes it lists under, best first. */
    private const VARIANT_SUBTYPE = [
        'normal' => ['Normal', 'Foil', 'Cold Foil'],
        'holo' => ['Holofoil', 'Foil', 'Cold Foil'],
        'reverse_holo' => ['Reverse Holofoil'],
    ];

    public function handle(TcgcsvClient $client, SeedSyntheticValuation $seed): int
    {
        $minRatio = max(1.0, (float) $this->option('min-ratio'));
        $dry = (bool) $this->option('dry-run');

        $sets = Set::query()
            ->with('productLine')
            ->whereNotNull('external_ids->tcgplayer_group_id')
            ->when($this->option('set'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $checked = $changed = 0;

        foreach ($sets as $set) {
            // The cards in this set carrying synthetic raw comps, with their median.
            $medians = $this->syntheticRawMedians($set->id);
            if ($medians === []) {
                continue;
            }

            $category = $this->category($set);
            try {
                $prices = collect($client->prices((int) $set->external_ids['tcgplayer_group_id'], $category))
                    ->groupBy(fn ($p) => (string) ($p['productId'] ?? ''));
            } catch (Throwable $e) {
                $this->warn("{$set->slug}: TCGCSV prices failed ({$e->getMessage()})");

                continue;
            }

            $items = CatalogItem::with('set')->whereIn('id', array_keys($medians))->get();

            foreach ($items as $item) {
                $productId = (string) ($item->external_ids['tcgplayer_product_id'] ?? '');
                $market = $this->marketCents($prices->get($productId)?->all() ?? [], $item);
                if ($productId === '' || $market === null) {
                    continue;
                }

                $checked++;
                $synthetic = $medians[$item->id];
                $ratio = $synthetic / $market;

                // Down only. Raising is where TCGplayer is least trustworthy: a
                // thin vintage Japanese market reads "$499.99" off one sale, and
                // an over-valued card misleads more than an under-valued one.
                if ($ratio < $minRatio) {
                    continue;
                }

                // The card's own sales outvote both. When real raw comps sit
                // nearer the synthetic price than the market, the market (or our
                // product link) is the wrong one.
                $real = $this->realRawMedian($item->id);
                if ($real !== null && abs(log($real / $synthetic)) < abs(log($real / $market))) {
                    $this->line(sprintf('kept %s #%s [%s]: its real sales ($%.2f) side with the synthetic $%.2f, not the market $%.2f',
                        $item->name, $item->number, $set->name, $real / 100, $synthetic / 100, $market / 100));

                    continue;
                }

                $changed++;
                $this->line(sprintf(
                    '%s %s #%s [%s] synthetic $%.2f → market $%.2f (%.1fx)',
                    $dry ? 'would re-seed' : 're-seeded',
                    $item->name, $item->number, $set->name,
                    $synthetic / 100, $market / 100, $ratio,
                ));

                if (! $dry) {
                    $seed($item, $market, gradedAnchors: $this->pricechartingGraded($productId, $item));
                }
            }
        }

        $this->info(sprintf(
            'checked %d card(s) against TCGplayer; %s %d (min ratio %.1fx)%s',
            $checked, $dry ? 'would re-seed' : 're-seeded', $changed, $minRatio, $dry ? ' — dry run, nothing written' : '',
        ));

        return self::SUCCESS;
    }

    /** @return array<int, int> catalog_item_id => median synthetic raw price (cents) */
    private function syntheticRawMedians(int $setId): array
    {
        $rows = DB::table('sale_observations as so')
            ->join('catalog_items as ci', 'ci.id', '=', 'so.catalog_item_id')
            ->where('ci.set_id', $setId)
            ->where('so.is_synthetic', true)
            ->where('so.is_outlier', false)
            ->whereNotNull('so.condition')
            ->get(['so.catalog_item_id', 'so.price']);

        return $rows->groupBy('catalog_item_id')
            ->map(fn ($g) => (int) $g->pluck('price')->median())
            ->filter(fn ($m) => $m > 0)
            ->all();
    }

    /** Median of the card's real raw sales, when it has at least three. */
    private function realRawMedian(int $itemId): ?int
    {
        $prices = DB::table('sale_observations')
            ->where('catalog_item_id', $itemId)
            ->where('is_synthetic', false)
            ->where('is_outlier', false)
            ->whereNotNull('condition')
            ->pluck('price');

        return $prices->count() >= 3 ? (int) $prices->median() : null;
    }

    /**
     * TCGplayer's market price for this printing, or null. Market only: the
     * mid and low are asks, which is how the bad anchors got here.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function marketCents(array $rows, CatalogItem $item): ?int
    {
        if ($rows === []) {
            return null;
        }

        $variant = $item->attributes['variant'] ?? null;
        $row = null;
        if ($variant === null || count($rows) === 1) {
            $row = $rows[0];
        } else {
            foreach (self::VARIANT_SUBTYPE[$variant] ?? [] as $subtype) {
                $row = collect($rows)->firstWhere('subTypeName', $subtype);
                if ($row !== null) {
                    break;
                }
            }
        }

        $value = $row['marketPrice'] ?? null;

        return $value ? (int) round((float) $value * 100) : null;
    }

    /**
     * PriceCharting's graded prices for this printing, in the seeder's format.
     * Only an unambiguous row: one per TCGplayer product in the card's language.
     *
     * @return array<int, array{company: string, grade: float, label: string, cents: int}>
     */
    private function pricechartingGraded(string $productId, CatalogItem $item): array
    {
        $rows = DB::table('pricecharting_products')
            ->where('tcg_id', $productId)
            ->where('language', $item->attributes['language'] ?? 'en')
            ->where('is_sealed', false)
            ->get();

        if ($rows->count() !== 1) {
            return [];
        }

        $row = $rows->first();
        $graded = [];
        foreach ([
            ['price_psa10', 'psa', 10.0, 'PSA 10'],
            ['price_grade9', 'psa', 9.0, 'PSA 9'],
            ['price_grade8', 'psa', 8.0, 'PSA 8'],
            ['price_bgs10', 'bgs', 10.0, 'BGS 10'],
        ] as [$column, $company, $grade, $label]) {
            if ((int) ($row->{$column} ?? 0) > 0) {
                $graded[] = ['company' => $company, 'grade' => $grade, 'label' => $label, 'cents' => (int) $row->{$column}];
            }
        }

        return $graded;
    }

    private function category(Set $set): int
    {
        return match (true) {
            $set->productLine?->slug === 'lorcana' => TcgcsvClient::LORCANA,
            $set->productLine?->slug === 'one-piece' => TcgcsvClient::ONE_PIECE,
            $set->language === 'ja' => TcgcsvClient::POKEMON_JAPAN,
            default => TcgcsvClient::POKEMON,
        };
    }
}
