<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\CardImageStore;
use App\Support\Pricecharting\CsvClient;
use App\Support\Pricecharting\PriceGuideParser;
use Illuminate\Support\Str;

/**
 * Fill missing Lorcana card images from TCGplayer's public product CDN, keyed by
 * tcgplayer_product_id. Targets only items with no image yet — the PriceCharting-
 * sourced sets (Wilds Unknown, Promo, Illumineer's Quest), which have no images,
 * plus any lorcana-api download that failed. A card's tcg id comes from its own
 * external_ids or, failing that, from any sibling row (e.g. a [Foil] printing) of
 * the same set+number in the price guide. Idempotent and best-effort.
 */
class BackfillLorcanaImages
{
    /** Sized ~100KB product image; full-res lives at product-images.tcgplayer.com/{id}.jpg. */
    private const CDN = 'https://tcgplayer-cdn.tcgplayer.com/product/%s_in_1000x1000.jpg';

    public function __construct(
        protected CsvClient $csv,
        protected PriceGuideParser $parser,
        protected CardImageStore $images,
    ) {}

    /**
     * @return array{candidates: int, stored: int, no_id: int, failed: int}
     */
    public function __invoke(int $limit = 0): array
    {
        $lorcana = ProductLine::where('slug', 'lorcana')->firstOrFail();

        // (normalized set name | number) => best TCGplayer product id from the guide.
        $tcgByCard = [];
        foreach (($this->parser)($this->csv->fetch('lorcana-cards')) as $row) {
            if ($row['number'] === null || $row['tcg_id'] === null) {
                continue;
            }
            $name = trim((string) preg_replace('/^Lorcana\s+/i', '', $row['set_name']));
            $tcgByCard[$this->norm($name).'|'.$row['number']] = (string) $row['tcg_id'];
        }

        $sets = Set::where('product_line_id', $lorcana->id)->get(['id', 'name', 'slug', 'code'])->keyBy('id');

        $query = CatalogItem::where('product_line_id', $lorcana->id)->whereNull('primary_image_path');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $stored = $noId = $failed = 0;
        $candidates = 0;

        foreach ($query->cursor() as $item) {
            $candidates++;
            $set = $sets->get($item->set_id);

            $tcgId = $item->external_ids['tcgplayer_product_id']
                ?? ($set ? ($tcgByCard[$this->norm($set->name).'|'.$item->number] ?? null) : null);

            if (! $tcgId) {
                $noId++;

                continue;
            }

            $setKey = $set?->code ?: ($set?->slug ?? 'unknown');
            $url = $this->images->store($setKey, (string) $item->id, sprintf(self::CDN, $tcgId), 'lorcana');

            if (! $url) {
                $failed++;

                continue;
            }

            $external = $item->external_ids ?? [];
            $external['tcgplayer_product_id'] = (string) $tcgId; // persist a newly-found id
            $item->forceFill(['primary_image_path' => $url, 'external_ids' => $external])->save();
            $stored++;
        }

        return ['candidates' => $candidates, 'stored' => $stored, 'no_id' => $noId, 'failed' => $failed];
    }

    private function norm(string $name): string
    {
        $n = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($name)));

        return (string) preg_replace('/^the/', '', $n);
    }
}
