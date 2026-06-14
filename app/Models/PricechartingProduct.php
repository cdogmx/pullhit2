<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A parsed PriceCharting price-guide product (one printing/edition/variant or a
 * sealed item). The reconciliation diffs these against {@see CatalogItem}.
 */
class PricechartingProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_sealed' => 'boolean',
            'release_date' => 'date',
            'raw' => 'array',
        ];
    }

    /** @return BelongsTo<Set, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }
}
