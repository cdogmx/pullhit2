<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A URL a card used to be reachable at. Written automatically whenever a card's
 * slug changes (see CatalogItem::booted), and read by the card route to redirect
 * an old link to the card's current address instead of 404ing.
 */
class CatalogItemSlugAlias extends Model
{
    protected $fillable = ['catalog_item_id', 'set_id', 'slug'];

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
