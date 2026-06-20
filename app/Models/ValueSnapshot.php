<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's headline value for a card's priced state. Written by the
 * valuation:snapshot command; read for the card-page value-over-time chart.
 */
#[Fillable([
    'catalog_item_id', 'state_key', 'median_cents',
    'n_sales', 'confidence', 'is_estimated', 'captured_on',
])]
class ValueSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'median_cents' => 'integer',
            'n_sales' => 'integer',
            'is_estimated' => 'boolean',
            'captured_on' => 'date',
        ];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
