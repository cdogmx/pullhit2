<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Pricecharting\GameCatalogParser;
use App\Support\Pricecharting\GameImportSpec;
use Illuminate\Support\Str;

/**
 * Build a catalog for a TCG (One Piece, Magic, Yu-Gi-Oh, …) from its PriceCharting
 * price-guide CSV: upsert the product line + each Set (from the console name),
 * create a catalog_item per single (images pulled from TCGplayer's CDN by tcg-id),
 * and seed an estimated value from the CSV prices. Idempotent and re-runnable.
 * Sealed product is skipped for now — singles first.
 */
class ImportPricechartingGame
{
    public function __construct(
        protected CreateCatalogItem $create,
        protected CardImageStore $images,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @return array{sets: int, items: int, images: int, priced: int}
     */
    public function __invoke(GameImportSpec $spec, string $csv, bool $withImages = true, bool $withPrices = true, ?int $limit = null): array
    {
        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $line = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => $spec->lineSlug],
            ['name' => $spec->lineName],
        );

        // Group singles by "set_name|language"; sealed product is skipped.
        $bySet = [];
        $count = 0;
        foreach ((new GameCatalogParser($spec))($csv) as $row) {
            if ($row['is_sealed'] || $row['name'] === '') {
                continue;
            }
            $bySet[$row['set_name'].'|'.$row['language']][] = $row;
            if ($limit !== null && ++$count >= $limit) {
                break;
            }
        }

        $stats = ['sets' => 0, 'items' => 0, 'images' => 0, 'priced' => 0];

        foreach ($bySet as $key => $rows) {
            [$setName, $language] = explode('|', $key, 2);
            $set = $this->upsertSet($line->id, $spec, $setName, $language, $rows);
            $stats['sets']++;

            foreach ($rows as $row) {
                $imagePath = null;
                if ($withImages && $row['tcg_id']) {
                    $imagePath = $this->images->store(
                        $set->code ?: $set->slug,
                        (string) ($row['tcg_id'] ?: $row['pc_id']),
                        "https://tcgplayer-cdn.tcgplayer.com/product/{$row['tcg_id']}_in_1000x1000.jpg",
                        $spec->lineSlug,
                    );
                    if ($imagePath) {
                        $stats['images']++;
                    }
                }

                $attributes = ['language' => $row['language'], 'variant' => $row['variant']];
                if ($row['finish']) {
                    $attributes['finish'] = $row['finish'];
                }

                $item = ($this->create)(
                    vertical: $vertical,
                    productLine: $line,
                    set: $set,
                    itemType: ItemType::Single,
                    name: $row['name'],
                    number: $row['number'],
                    attributes: $attributes,
                    externalIds: array_filter([
                        'pricecharting_id' => $row['pc_id'],
                        'tcgplayer_product_id' => $row['tcg_id'],
                    ]),
                    primaryImagePath: $imagePath,
                );
                $stats['items']++;

                if ($withPrices) {
                    $this->seedValue($item, $row['prices']) && $stats['priced']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function upsertSet(int $productLineId, GameImportSpec $spec, string $setName, string $language, array $rows): Set
    {
        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where('language', $language)
            ->where('name', $setName)
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => Str::slug($spec->lineSlug.'-'.$setName.'-'.$language),
            'name' => $setName,
            'code' => $this->deriveCode($rows),
            'language' => $language,
            'set_family' => $setName,
        ])->save();

        return $set;
    }

    /** The most common collector-number prefix becomes the set code (e.g. "OP07"). */
    private function deriveCode(array $rows): ?string
    {
        $prefixes = [];
        foreach ($rows as $row) {
            if ($row['number'] && preg_match('/^([A-Z0-9]+)-/i', $row['number'], $m)) {
                $prefixes[strtoupper($m[1])] = ($prefixes[strtoupper($m[1])] ?? 0) + 1;
            }
        }
        if ($prefixes === []) {
            return null;
        }
        arsort($prefixes);

        return (string) array_key_first($prefixes);
    }

    /** @param  array<string, int|null>  $prices */
    private function seedValue($item, array $prices): bool
    {
        $graded = [];
        foreach ([
            ['psa10', 'psa', 10.0, 'PSA 10'],
            ['grade9', 'psa', 9.0, 'PSA 9'],
            ['grade8', 'psa', 8.0, 'PSA 8'],
            ['bgs10', 'bgs', 10.0, 'BGS 10'],
        ] as [$key, $company, $grade, $label]) {
            if (! empty($prices[$key])) {
                $graded[] = ['company' => $company, 'grade' => $grade, 'label' => $label, 'cents' => $prices[$key]];
            }
        }

        $ungraded = (int) ($prices['ungraded'] ?? 0);
        if ($ungraded <= 0 && $graded === []) {
            return false;
        }

        ($this->seed)($item, $ungraded, gradedAnchors: $graded);

        return true;
    }
}
