<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card's long-term monthly price series from PriceCharting, kept out of
 * catalog_items because it averaged ~1 KB a row (15 KB at the top end) on only
 * 13% of rows, and every list query selected it without ever reading it.
 *
 * Shape: {"ungraded": [{t, price}, …], "9": […], "10": […]}. A legacy flat list
 * means the ungraded series alone — see CatalogItem::longTermHistoryTiers().
 */
class CatalogItemPriceHistory extends Model
{
    protected $fillable = ['catalog_item_id', 'history'];

    protected function casts(): array
    {
        return ['history' => 'array'];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
