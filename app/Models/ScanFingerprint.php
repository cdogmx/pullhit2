<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A perceptual-hash → catalog-item association learned from a confirmed scan.
 * See the migration for the recognition-cache rationale.
 */
class ScanFingerprint extends Model
{
    /** @use HasFactory<\Database\Factories\ScanFingerprintFactory> */
    use HasFactory;

    protected $fillable = [
        'catalog_item_id',
        'phash',
        'confirmations',
        'last_user_id',
    ];

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }
}
