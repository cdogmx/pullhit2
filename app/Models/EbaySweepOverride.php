<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sticky admin decision for one eBay listing: 'reject' (never apply) or
 * 'reassign' (always apply to catalog_item_id). Consulted by SweepEbaySold
 * before matching. See App\Http\Controllers\Admin\EbaySweepController.
 */
#[Fillable([
    'source_listing_id',
    'action',
    'catalog_item_id',
    'title',
    'created_by',
])]
class EbaySweepOverride extends Model
{
    public const REJECT = 'reject';

    public const REASSIGN = 'reassign';

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
