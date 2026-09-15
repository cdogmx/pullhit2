<?php

namespace App\Support\Marketplace;

use App\Models\CatalogItem;

/**
 * The shape the listing form's card picker speaks.
 *
 * Three places produce it — the catalogue search behind the picker, the prefill
 * that arrives with "List for sale", and an existing listing being edited — and
 * a picker handed three slightly different shapes renders a card with holes in
 * it. One shaper, so they cannot drift.
 */
class CardHit
{
    /** @return array<string, mixed> */
    public static function for(CatalogItem $item, ?int $marketCents = null): array
    {
        return [
            'id' => $item->id,
            'name' => $item->display_name,
            'number' => $item->number,
            'set' => $item->set?->name,
            'set_code' => $item->set?->code,
            'line' => $item->productLine?->name,
            'thumb' => $item->primary_image_path,
            'market_cents' => $marketCents,
            'url' => $item->path(),
        ];
    }
}
