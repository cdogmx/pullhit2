<?php

namespace App\Models;

use Database\Factories\SetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A release/set within a product line. Language-specific for TCG; series/
 * set_family group cross-language equivalents.
 */
#[Fillable([
    'product_line_id',
    'slug',
    'name',
    'code',
    'language',
    'series',
    'set_family',
    'released_at',
    'external_ids',
])]
class Set extends Model
{
    /** @use HasFactory<SetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'released_at' => 'date',
            'external_ids' => 'array',
        ];
    }

    /** @return BelongsTo<ProductLine, $this> */
    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class);
    }

    /** @return HasMany<CatalogItem, $this> */
    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }
}
