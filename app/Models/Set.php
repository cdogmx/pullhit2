<?php

namespace App\Models;

use Database\Factories\SetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    'logo_path',
    'og_image_path',
    'og_image_at',
    'description',
    'code',
    'ebay_set',
    'ebay_set_learned_at',
    'language',
    'series',
    'set_family',
    'expansion_key',
    'released_at',
    'refresh_minutes',
    'refresh_boost_until',
    'featured_until',
    'featured_blurb',
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
            'ebay_set_learned_at' => 'datetime',
            'refresh_boost_until' => 'datetime',
            'featured_until' => 'datetime',
            'og_image_at' => 'datetime',
            'external_ids' => 'array',
        ];
    }

    /**
     * How often this set's prices may be refetched on a card view, in minutes —
     * null when it follows the global default.
     *
     * A set in its first week moves faster than the catalog around it, so it can
     * be given a shorter TTL that expires on its own. See the migration adding
     * these columns, and valuation:boost-set.
     */
    public function refreshMinutes(): ?int
    {
        if ($this->refresh_minutes === null || $this->refresh_boost_until === null) {
            return null;
        }

        return $this->refresh_boost_until->isFuture() ? (int) $this->refresh_minutes : null;
    }

    /**
     * Sets with a live spot on the home page, newest first.
     *
     * @param  Builder<Set>  $query
     */
    public function scopeFeatured(Builder $query): void
    {
        $query->whereNotNull('featured_until')
            ->where('featured_until', '>', now())
            ->orderByDesc('released_at');
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

    /** @return HasMany<SetPullOdd, $this> */
    public function pullOdds(): HasMany
    {
        return $this->hasMany(SetPullOdd::class);
    }
}
