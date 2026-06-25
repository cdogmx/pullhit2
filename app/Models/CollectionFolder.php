<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shareable metadata for a folder within a collection — a slug and a public/
 * private flag. The folder's membership lives on collection_items.folder (by
 * name); this row carries only the link + visibility.
 */
class CollectionFolder extends Model
{
    protected $fillable = ['collection_id', 'name', 'slug', 'is_public', 'sort'];

    /** Mirror the DB defaults so a freshly-created row reports them in-memory. */
    protected $attributes = ['is_public' => false, 'sort' => 0];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }
}
