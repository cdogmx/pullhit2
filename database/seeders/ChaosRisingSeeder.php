<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateCatalogItem;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the Pokémon "Chaos Rising" (Mega Evolution series, English) set and its
 * full card list from a normalized fixture sourced from pokemontcg.io (set me4).
 *
 * The fixture (database/data/chaos-rising-en.json) is a hand-made manifest — the
 * same shape the Phase 2 importer will consume. Idempotent: re-running upserts
 * the set and dedups items on their identity_hash.
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

        foreach ($fixture['cards'] as $card) {
            $create(
                vertical: $vertical,
                productLine: $pokemon,
                set: $set,
                itemType: ItemType::Single,
                name: $card['name'],
                number: $card['number'],
                attributes: $card['attributes'],
                externalIds: $card['external_ids'] ?? [],
            );
        }

        $this->command?->info("Chaos Rising: seeded {$set->catalogItems()->count()} cards.");
    }

    /**
     * @return array{set: array<string, mixed>, cards: array<int, array<string, mixed>>}
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
