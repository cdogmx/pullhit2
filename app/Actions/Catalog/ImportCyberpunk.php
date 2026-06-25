<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\CyberpunkApiClient;
use Illuminate\Support\Str;

/**
 * Import the Cyberpunk TCG into the catalog from the Netdeck.gg API: upsert the
 * cyberpunk product line + each set, create a catalog_item per card, and store
 * each card image in our bucket. Idempotent — keyed on the card's stable API id,
 * so a re-run is a clean upsert. No price seeding (valuations come from eBay).
 */
class ImportCyberpunk
{
    public function __construct(
        protected CyberpunkApiClient $api,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
    ) {}

    /**
     * @return array{cards: int, images: int, sets: array<int, string>, sample: array<int, array<string, mixed>>}
     */
    public function __invoke(bool $dryRun = false, bool $withImages = true, ?int $limit = null): array
    {
        $cards = $this->api->cards();
        if ($limit !== null) {
            $cards = array_slice($cards, 0, $limit);
        }

        if ($dryRun) {
            return [
                'cards' => count($cards),
                'images' => 0,
                'sets' => array_values(array_unique(array_filter(array_map(fn ($c) => $c['set']['name'] ?? null, $cards)))),
                'sample' => array_slice($cards, 0, 8),
            ];
        }

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $line = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'cyberpunk'],
            ['name' => 'Cyberpunk TCG'],
        );

        $setCache = [];
        $imageCount = 0;
        $setNames = [];

        foreach ($cards as $card) {
            $code = $card['set']['code'] ?? 'cyberpunk';
            $set = $setCache[$code] ??= $this->upsertSet($line->id, $code, $card['set']['name'] ?? 'Cyberpunk TCG');
            $setNames[$set->name] = true;

            $imageUrl = null;
            if ($withImages) {
                $imageUrl = $this->images->store($set->slug, (string) $card['id'], $card['image_url'] ?? null, 'cyberpunk');
                if ($imageUrl) {
                    $imageCount++;
                }
            }

            $classifications = $card['classifications'] ?? [];

            ($this->create)(
                vertical: $vertical,
                productLine: $line,
                set: $set,
                itemType: ItemType::Single,
                name: $card['display_name'] ?? $card['name'],
                number: $card['print_number'] ?? null,
                attributes: array_filter([
                    'language' => 'en',
                    'variant' => 'normal',
                    'rarity' => $card['rarity'] ?? null,
                    'type' => $card['card_type'] ?? null,
                    'faction' => $card['color'] ?? null,
                    'classifications' => is_array($classifications) ? implode(', ', $classifications) : null,
                    'illustrator' => $card['artist'] ?? null,
                    'cost' => $card['cost'] ?? null,
                    'power' => $card['power'] ?? null,
                    'ram' => $card['ram'] ?? null,
                    'body_text' => $card['rules_text'] ?? null,
                    'flavor_text' => $card['flavor_text'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
                externalIds: array_filter([
                    'cyberpunk_id' => $card['id'] ?? null,
                    'cyberpunk_external_id' => $card['external_id'] ?? null,
                    'cyberpunk_printing_id' => $card['printing_id'] ?? null,
                    'cyberpunk_slug' => $card['slug'] ?? null,
                ]),
                primaryImagePath: $imageUrl,
            );
        }

        return [
            'cards' => count($cards),
            'images' => $imageCount,
            'sets' => array_keys($setNames),
            'sample' => [],
        ];
    }

    protected function upsertSet(int $productLineId, string $code, string $name): Set
    {
        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where('external_ids->cyberpunk_set_code', $code)
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => Str::slug($name) ?: $code,
            'name' => $name,
            'code' => $code,
            'language' => 'en',
            'set_family' => $name,
            'external_ids' => ['cyberpunk_set_code' => $code],
        ])->save();

        return $set;
    }
}
