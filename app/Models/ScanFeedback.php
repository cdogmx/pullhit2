<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "was this detection correct?" report for a scanned card.
 */
#[Fillable([
    'user_id', 'source', 'phash', 'was_correct', 'identified',
    'detected_catalog_item_id', 'corrected_catalog_item_id',
])]
class ScanFeedback extends Model
{
    protected $table = 'scan_feedback';

    protected function casts(): array
    {
        return [
            'was_correct' => 'boolean',
            'identified' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function detectedItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'detected_catalog_item_id');
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function correctedItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'corrected_catalog_item_id');
    }
}
