<?php

namespace App\Actions\Catalog;

use App\Models\Set;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Support\Str;

/**
 * Backfills images (and rarity) for catalog cards that pokemontcg.io hasn't
 * published yet, sourcing them from TCGCSV (a free TCGplayer mirror). Matches
 * the set's image-less numbered cards to TCGplayer products by collector
 * number, stores our own copy of each image in S3, and fills in the rarity
 * when it's still "Unknown". Idempotent — only touches rows missing an image,
 * so re-running is safe and a later pokemontcg.io import still wins.
 */
class BackfillTcgplayerImages
{
    public function __construct(
        protected TcgcsvClient $tcgcsv,
        protected CardImageStore $images,
    ) {}

    /**
     * @return array{candidates:int, matched:int, stored:int, rarities:int, unmatched:array<int,string>}
     */
    public function __invoke(
        Set $set,
        int $groupId,
        int $category = TcgcsvClient::POKEMON,
        bool $fillRarity = true,
        bool $dryRun = false,
    ): array {
        $byNumber = $this->indexByNumber($this->tcgcsv->products($groupId, $category));

        $setKey = data_get($set->external_ids, 'ptcgio_id') ?: (string) $set->id;

        $candidates = $set->catalogItems()
            ->whereNull('primary_image_path')
            ->whereNotNull('number')
            ->where('number', '!=', '')
            ->get();

        $matched = 0;
        $stored = 0;
        $rarities = 0;
        $unmatched = [];

        foreach ($candidates as $item) {
            $product = $byNumber[$this->normalize((string) $item->number)] ?? null;

            if (! $product) {
                $unmatched[] = (string) $item->number;

                continue;
            }

            $matched++;

            $productId = (string) ($product['productId'] ?? '');
            $imageUrl = $productId !== ''
                ? "https://tcgplayer-cdn.tcgplayer.com/product/{$productId}_in_1000x1000.jpg"
                : ($product['imageUrl'] ?? null);

            if ($dryRun) {
                $stored++;

                continue;
            }

            $url = $this->images->store($setKey, 'tcg-'.$productId, $imageUrl, 'pokemon');

            if (! $url) {
                continue;
            }

            $item->primary_image_path = $url;
            $stored++;

            if ($fillRarity) {
                $rarity = $this->extended($product, 'Rarity');
                $current = $item->attributes['rarity'] ?? null;

                if ($rarity && (blank($current) || $current === 'Unknown')) {
                    $item->attributes = ['rarity' => $rarity] + ($item->attributes ?? []);
                    $rarities++;
                }
            }

            $item->save();
        }

        return [
            'candidates' => $candidates->count(),
            'matched' => $matched,
            'stored' => $stored,
            'rarities' => $rarities,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Index products by normalized collector number, keeping the first product
     * seen per number (TCGplayer lists one product per card).
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, array<string, mixed>>
     */
    private function indexByNumber(array $products): array
    {
        $byNumber = [];

        foreach ($products as $product) {
            $number = $this->extended($product, 'Number');

            if (! $number) {
                continue;
            }

            $key = $this->normalize($number);

            if (! isset($byNumber[$key])) {
                $byNumber[$key] = $product;
            }
        }

        return $byNumber;
    }

    /**
     * Normalize a collector number for matching: take the part before any "/"
     * ("294/217" → "294") and strip leading zeros ("007" → "7").
     */
    private function normalize(string $number): string
    {
        $head = Str::of($number)->before('/')->trim()->toString();

        return ltrim($head, '0') ?: $head;
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function extended(array $product, string $name): ?string
    {
        foreach ($product['extendedData'] ?? [] as $field) {
            if (($field['name'] ?? null) === $name) {
                return $field['value'] ?? null;
            }
        }

        return null;
    }
}
