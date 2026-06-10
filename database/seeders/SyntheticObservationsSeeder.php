<?php

namespace Database\Seeders;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Database\Seeder;

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
    public function run(): void
    {
        $path = database_path('data/chaos-rising-prices.json');
        $set = Set::where('slug', 'chaos-rising-en')->first();
        if (! $set || ! is_file($path)) {
            return;
        }

        $prices = json_decode((string) file_get_contents($path), true);
        $seed = app(SeedSyntheticValuation::class);

        foreach (CatalogItem::where('set_id', $set->id)->orderBy('id')->get() as $item) {
            $rows = $prices[(string) ($item->external_ids['tcgplayer_product_id'] ?? null)] ?? null;
            if (! $rows) {
                continue;
            }

            $variant = $item->attributes['variant'] ?? null;
            $row = collect($rows)->firstWhere('variant', $variant) ?? $rows[0];

            $seed($item, (int) $row['market'], (int) $row['low'], (int) $row['high']);
        }
    }
}
