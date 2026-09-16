<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One intraday reading of a card's value. See the migration for why this exists
 * alongside value_snapshots, and for what it does and does not measure.
 */
class ValueTick extends Model
{
    protected $fillable = [
        'catalog_item_id', 'state_key', 'median_cents', 'for_sale_cents', 'n_sales', 'captured_at',
    ];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
