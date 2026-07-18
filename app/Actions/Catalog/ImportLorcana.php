<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\LorcanaApiClient;
use App\Support\Catalog\LorcanaCardMapper;
use Illuminate\Support\Str;

/**
 * Import Disney Lorcana cards from lorcana-api.com into the catalog: upsert the
 * lorcana product line + each Set, create a catalog_item per card (via
 * CreateCatalogItem), and store each card image in our S3 bucket. Idempotent and
 * re-runnable. The bulk feed returns every card in one call, so we fetch once and
 * group by set. No price seeding — valuations come from eBay sold comps.
 */
class ImportLorcana
{
    public function __construct(
        protected LorcanaApiClient $client,
        protected LorcanaCardMapper $mapper,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
    ) {}

    /**
     * @param  array<int, string>  $setIds  Set_IDs to import (e.g. ['ARI','INK']); empty = all
     * @return array{sets: array<int, array{set: string, items: int, images: int}>}
     */
    public function __invoke(array $setIds = [], bool $withImages = true): array
    {
        $only = array_map('strtoupper', $setIds);

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $lorcana = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'lorcana'],
            ['name' => 'Disney Lorcana'],
        );

        // Group the full feed by Set_ID; each group seeds one Set + its cards.
        $bySet = [];
        foreach ($this->client->allCards() as $card) {
            $setId = (string) ($card['Set_ID'] ?? '');
            if ($setId === '' || ($only !== [] && ! in_array($setId, $only, true))) {
                continue;
            }
            $bySet[$setId][] = $card;
        }

        $summaries = [];

        foreach ($bySet as $setId => $cards) {
            $set = $this->upsertSet($lorcana->id, $setId, $cards[0]);

            $items = 0;
            $imageCount = 0;

            foreach ($cards as $card) {
                $imageUrl = null;
                if ($withImages) {
                    $imageUrl = $this->images->store(
                        $setId,
                        (string) ($card['Unique_ID'] ?? ''),
                        $card['Image'] ?? null,
                        'lorcana',
                    );
                    if ($imageUrl) {
                        $imageCount++;
                    }
                }

                foreach ($this->mapper->map($card) as $mapped) {
                    ($this->create)(
                        vertical: $vertical,
                        productLine: $lorcana,
                        set: $set,
                        itemType: ItemType::Single,
                        name: $mapped->name,
                        number: $mapped->number,
                        attributes: $mapped->attributes,
                        externalIds: $mapped->externalIds,
                        primaryImagePath: $imageUrl,
                    );
                    $items++;
                }
            }

            $summaries[] = ['set' => $set->name, 'items' => $items, 'images' => $imageCount];
        }

        return ['sets' => $summaries];
    }

    /** @param  array<string, mixed>  $sampleCard  any card from the set (for its name/num) */
    protected function upsertSet(int $productLineId, string $setId, array $sampleCard): Set
    {
        $name = $sampleCard['Set_Name'] ?? $setId;

        $slug = Str::slug($name) ?: strtolower($setId);

        // Match on the stable source id first, then fall back to the slug. The id
        // match means renaming a set can never duplicate it on re-import; the slug
        // fallback catches a set TCGCSV seeded on release day (keyed by TCGplayer
        // group id, before this API published it) so we refine that row instead of
        // creating a second copy of the whole set.
        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where(fn ($q) => $q
                ->where('external_ids->lorcana_set_id', $setId)
                ->orWhere('slug', $slug))
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => $slug,
            'name' => $name,
            'code' => $setId, // the short set code (e.g. "ARI")
            'language' => 'en',
            'set_family' => $name,
            // Merge, so a TCGplayer group id already on the row survives.
            'external_ids' => array_merge((array) ($set->external_ids ?? []), array_filter([
                'lorcana_set_id' => $setId,
                'lorcana_set_num' => $sampleCard['Set_Num'] ?? null,
            ], fn ($v) => $v !== null)),
        ])->save();

        return $set;
    }
}
