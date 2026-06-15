<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-submitted correction to a catalog item, awaiting admin review.
 * `changes` holds only the proposed field overrides.
 */
class ItemEditSuggestion extends Model
{
    /** Catalog fields a user may suggest editing. Top-level vs attribute facets. */
    public const TOP_LEVEL = ['name', 'number'];

    public const ATTRIBUTES = ['rarity', 'variant', 'edition', 'language', 'illustrator'];

    protected $fillable = ['user_id', 'catalog_item_id', 'changes', 'note', 'status', 'reviewed_by', 'review_note', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public static function editableFields(): array
    {
        return array_merge(self::TOP_LEVEL, self::ATTRIBUTES);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CatalogItem, $this> */
    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
