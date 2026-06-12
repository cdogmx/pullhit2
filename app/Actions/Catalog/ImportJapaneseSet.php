<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\JapaneseCardMapper;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a Japanese Pokémon set from TCGCSV (TCGplayer category 85) into the
 * catalog: upsert the (language=ja) Set, create a catalog_item per card finish
 * via the shared CreateCatalogItem, store images, and seed estimated values.
 * The Japanese sibling of ImportPokemonSet; idempotent and re-runnable.
 */
class ImportJapaneseSet
{
    public function __construct(
        protected TcgcsvClient $client,
        protected JapaneseCardMapper $mapper,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @return array{set: string, items: int, valued: int, images: int}
     */
    public function __invoke(int $groupId, bool $withPrices = true, bool $withImages = true): array
    {
        $group = collect($this->client->groups())->firstWhere('groupId', $groupId)
            ?? throw new RuntimeException("Pokemon Japan group [{$groupId}] not found");

        $products = $this->client->products($groupId);
        $pricesByProduct = collect($this->client->prices($groupId))->groupBy('productId');

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $pokemon = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'pokemon'],
            ['name' => 'Pokémon'],
        );
        $set = $this->upsertSet($pokemon->id, $group);

        $items = 0;
        $valued = 0;
        $imageCount = 0;

        foreach ($products as $product) {
            $productId = (string) ($product['productId'] ?? '');

            $imageUrl = null;
            if ($withImages) {
                $imageUrl = $this->images->store("jp-{$groupId}", $productId, $product['imageUrl'] ?? null);
                if ($imageUrl) {
                    $imageCount++;
                }
            }

            $prices = ($pricesByProduct->get($product['productId'] ?? null) ?? collect())->all();

            foreach ($this->mapper->map($product, $prices) as $mapped) {
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

    /** @param  array<string, mixed>  $group */
    protected function upsertSet(int $productLineId, array $group): Set
    {
        $fullName = $group['name'] ?? ('Group '.($group['groupId'] ?? ''));

        // TCGplayer prefixes the set code: "M3: Nihil Zero" → code "M3", name
        // "Nihil Zero" (which also matches PriceCharting's naming).
        $code = null;
        $name = $fullName;
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.+)$/', $fullName, $m)) {
            $code = $m[1];
            $name = trim($m[2]);
        }

        $slug = (Str::slug($name) ?: 'set-'.($group['groupId'] ?? 'x')).'-ja';

        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where('external_ids->tcgplayer_group_id', (string) $group['groupId'])
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => $slug,
            'name' => $name,
            'code' => $code,
            'language' => 'ja',
            // Clean name links a JP set to its EN equivalent where one exists.
            'set_family' => $name,
            'released_at' => isset($group['publishedOn']) ? substr((string) $group['publishedOn'], 0, 10) : null,
            'external_ids' => ['tcgplayer_group_id' => (string) $group['groupId']],
        ])->save();

        return $set;
    }
}
