<?php

namespace App\Models;

use App\Enums\ItemType;
use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value-bearing unit — generic across all verticals (§4.1). Vertical-specific
 * facets live in `attributes` (JSON, described by the Vertical registry).
 *
 * `language` is a read-only generated column (derived from attributes->language);
 * `identity_hash` is set by App\Actions\Catalog\CreateCatalogItem. Both are
 * intentionally excluded from mass assignment.
 */
#[Fillable([
    'vertical_id',
    'product_line_id',
    'set_id',
    'item_type',
    'name',
    'number',
    'attributes',
    'primary_image_path',
    'external_ids',
])]
class CatalogItem extends Model
{
    /** @use HasFactory<CatalogItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'attributes' => 'array',
            'external_ids' => 'array',
        ];
    }

    /** @return BelongsTo<Vertical, $this> */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /** @return BelongsTo<ProductLine, $this> */
    public function productLine(): BelongsTo
    {
        return $this->belongsTo(ProductLine::class);
    }

    /** @return BelongsTo<Set, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }
}
