<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\PokemonCardMapper;
use App\Support\Catalog\PokemonTcgClient;

/**
 * Import a real Pokémon set from pokemontcg.io into the catalog: upsert the Set,
 * create a catalog_item per card finish (via CreateCatalogItem), store each card
 * image in our S3 bucket, and seed estimated valuations from TCGplayer prices.
 * Idempotent and re-runnable. The Phase-2 generalization of ChaosRisingSeeder.
 */
class ImportPokemonSet
{
    public function __construct(
        protected PokemonTcgClient $client,
        protected PokemonCardMapper $mapper,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @return array{set: string, items: int, valued: int, images: int}
     */
    public function __invoke(string $setId, bool $withPrices = true, bool $withImages = true): array
    {
        $setData = $this->client->set($setId);
        $cards = $this->client->cards($setId);

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $pokemon = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'pokemon'],
            ['name' => 'Pokémon'],
        );
        $set = $this->upsertSet($pokemon->id, $setId, $setData);

        $items = 0;
        $valued = 0;
        $imageCount = 0;

        foreach ($cards as $card) {
            $imageUrl = null;
            if ($withImages) {
                $imageUrl = $this->images->store(
                    $setId,
                    (string) ($card['id'] ?? ''),
                    data_get($card, 'images.large') ?? data_get($card, 'images.small'),
                );
                if ($imageUrl) {
                    $imageCount++;
                }
            }

            foreach ($this->mapper->map($card) as $mapped) {
                $item = ($this->create)(
                    vertical: $vertical,
                    productLine: $pokemon,
                    set: $set,
                    itemType: ItemType::Single,
                    name: $mapped->name,
                    number: $mapped->number,
                    attributes: $mapped->attributes,
                    externalIds: $mapped->externalIds,
                    primaryImagePath: $imageUrl,
                );
                $items++;

                if ($withPrices && $mapped->anchorCents > 0) {
                    ($this->seed)($item, $mapped->anchorCents);
                    $valued++;
                }
            }
        }

        return ['set' => $set->name, 'items' => $items, 'valued' => $valued, 'images' => $imageCount];
    }

    /** @param  array<string, mixed>  $data */
    protected function upsertSet(int $productLineId, string $setId, array $data): Set
    {
        return Set::updateOrCreate(
            ['product_line_id' => $productLineId, 'slug' => "{$setId}-en"],
            [
                'name' => $data['name'] ?? $setId,
                'code' => $data['ptcgoCode'] ?? null,
                'language' => 'en',
                'series' => $data['series'] ?? null,
                'set_family' => $data['series'] ?? null,
                'released_at' => isset($data['releaseDate']) ? str_replace('/', '-', $data['releaseDate']) : null,
                'external_ids' => ['ptcgio_id' => $setId],
            ],
        );
    }
}
