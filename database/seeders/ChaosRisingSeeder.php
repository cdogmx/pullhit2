<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the Pokémon "Chaos Rising" (Mega Evolution series, English) set at the
 * printing/variant level: every TCGplayer printing of every card is its own
 * catalog_item (grouped by base_key), plus the set's sealed products.
 *
 * Data: database/data/chaos-rising-en.json — a normalized manifest built from
 * TCGCSV (authoritative printings) enriched by pokemontcg.io. Idempotent: items
 * upsert on identity_hash, and stale rows for this set are reconciled away.
 */
class ChaosRisingSeeder extends Seeder
{
    public function run(): void
    {
        $fixture = $this->loadFixture();

        $vertical = Vertical::updateOrCreate(
            ['slug' => 'tcg'],
            ['name' => 'Trading Card Games'],
        );

        $pokemon = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'pokemon'],
            ['name' => 'Pokémon'],
        );

        $setData = $fixture['set'];
        $set = Set::updateOrCreate(
            ['product_line_id' => $pokemon->id, 'slug' => $setData['slug']],
            [
                'name' => $setData['name'],
                'code' => $setData['code'],
                'language' => $setData['language'],
                'series' => $setData['series'],
                'set_family' => $setData['set_family'],
                'released_at' => $setData['released_at'],
                'external_ids' => $setData['external_ids'],
            ],
        );

        $create = app(CreateCatalogItem::class);
        $seenHashes = [];

        foreach ($fixture['singles'] as $card) {
            $item = $create(
                vertical: $vertical,
                productLine: $pokemon,
                set: $set,
                itemType: ItemType::Single,
                name: $card['name'],
                number: $card['number'],
                attributes: $card['attributes'],
                externalIds: $card['external_ids'] ?? [],
            );
            $seenHashes[] = $item->identity_hash;
        }

        foreach ($fixture['sealed'] as $product) {
            $item = $create(
                vertical: $vertical,
                productLine: $pokemon,
                set: $set,
                itemType: ItemType::Sealed,
                name: $product['name'],
                attributes: $product['attributes'],
                externalIds: $product['external_ids'] ?? [],
            );
            $seenHashes[] = $item->identity_hash;
        }

        // Reconcile: drop any earlier rows for this set that are no longer in the
        // manifest (e.g. the previous base-only singles), keeping the seeder idempotent.
        $removed = CatalogItem::where('set_id', $set->id)
            ->whereNotIn('identity_hash', $seenHashes)
            ->delete();

        $this->command?->info(sprintf(
            'Chaos Rising: %d singles + %d sealed seeded (%d distinct base cards); %d stale rows removed.',
            count($fixture['singles']),
            count($fixture['sealed']),
            CatalogItem::where('set_id', $set->id)->distinct('base_key')->count('base_key'),
            $removed,
        ));
    }

    /**
     * @return array{set: array<string, mixed>, singles: array<int, array<string, mixed>>, sealed: array<int, array<string, mixed>>}
     */
    protected function loadFixture(): array
    {
        $path = database_path('data/chaos-rising-en.json');

        if (! is_file($path)) {
            throw new RuntimeException("Chaos Rising fixture not found at [{$path}].");
        }

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }
}
