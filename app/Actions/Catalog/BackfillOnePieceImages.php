<?php

namespace App\Actions\Catalog;

use App\Models\Set;
use App\Support\Catalog\CardImageStore;

/**
 * Backfills One Piece card images straight from the official card-list CDN,
 * whose image URL is just the collector number: `.../card/{number}.png`
 * (parallels carry a `_p1` suffix in the number, e.g. OP16-001_p1). The
 * Japanese domain serves Japanese art, the English domain English art — pick
 * the base that matches the set's language. Only touches rows missing an image,
 * so it's idempotent and safe to re-run; misses (numbers the CDN doesn't host)
 * are returned for follow-up.
 */
class BackfillOnePieceImages
{
    /** Official card-list image CDN — Japanese artwork. */
    public const JP_BASE = 'https://www.onepiece-cardgame.com/images/cardlist/card/';

    /** Official card-list image CDN — English artwork. */
    public const EN_BASE = 'https://en.onepiece-cardgame.com/images/cardlist/card/';

    public function __construct(protected CardImageStore $images) {}

    /**
     * @return array{candidates:int, stored:int, missing:array<int,string>}
     */
    public function __invoke(Set $set, ?string $base = null, bool $dryRun = false): array
    {
        $base ??= self::JP_BASE;

        $items = $set->catalogItems()
            ->whereNull('primary_image_path')
            ->whereNotNull('number')
            ->where('number', '!=', '')
            ->get();

        $stored = 0;
        $missing = [];

        foreach ($items as $item) {
            $number = (string) $item->number;
            $url = $base.rawurlencode($number).'.png';

            if ($dryRun) {
                $stored++;

                continue;
            }

            // Key by number so a card's variants share one stored copy.
            $key = 'op-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $number);
            $storedUrl = $this->images->store((string) $set->id, $key, $url, 'one-piece');

            if ($storedUrl) {
                $item->primary_image_path = $storedUrl;
                $item->save();
                $stored++;
            } else {
                $missing[] = $number;
            }
        }

        return [
            'candidates' => $items->count(),
            'stored' => $stored,
            'missing' => $missing,
        ];
    }
}
