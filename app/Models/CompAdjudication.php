<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comp the AI pass thinks belongs to a different card.
 *
 * Open until somebody decides. The model never acts on its own here: it reads a
 * title into fields, a deterministic matcher places those fields, and the
 * disagreement is recorded for a person — which is the whole reason AI is
 * affordable in this system at all.
 */
class CompAdjudication extends Model
{
    public const OPEN = 'open';

    public const APPLIED = 'applied';

    public const DISMISSED = 'dismissed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'ratio' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function readsAs(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'reads_as_catalog_item_id');
    }

    /** @return BelongsTo<SaleObservation, $this> */
    public function saleObservation(): BelongsTo
    {
        return $this->belongsTo(SaleObservation::class);
    }
}
