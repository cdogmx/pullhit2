<?php

namespace App\Models;

use App\Enums\ItemType;
use App\Support\Catalog\CardDisplayName;
use Database\Factories\CatalogItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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
    'msrp',
    'released_at',
    'retailer_links',
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
            'msrp' => 'integer',
            'released_at' => 'date',
            'retailer_links' => 'array',
            'popularity' => 'integer',
            'last_viewed_at' => 'datetime',
            'ebay_refreshed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // New cards get a URL slug, unique within their set.
        static::creating(function (CatalogItem $item) {
            if (empty($item->slug)) {
                $item->slug = $item->buildUniqueSlug();
            }
        });
    }

    /**
     * The card's canonical path: /{brand}/{set}/{card-slug}. Null when the brand,
     * set, or slug is missing — callers fall back to /catalog/{id}. Requires the
     * productLine + set relations to be loaded (no lazy query in list contexts).
     */
    public function path(): ?string
    {
        $brand = $this->productLine?->slug;
        $set = $this->set?->slug;

        return $brand && $set && $this->slug ? "/{$brand}/{$set}/{$this->slug}" : null;
    }

    /**
     * The slug stem from the display name + collector number, e.g.
     * "Charizard ex (Reverse Holo)" #199 → "charizard-ex-reverse-holo-199".
     */
    public function slugBase(): string
    {
        $base = Str::slug($this->display_name);
        if ($this->number) {
            $base = trim($base.'-'.Str::slug((string) $this->number), '-');
        }

        return $base !== '' ? $base : 'card-'.($this->id ?? Str::random(6));
    }

    /** A slug that doesn't collide with another card in the same set. */
    public function buildUniqueSlug(): string
    {
        $base = $this->slugBase();
        $slug = $base;
        $n = 2;

        while (self::query()
            ->where('set_id', $this->set_id)
            ->where('slug', $slug)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->exists()
        ) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
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

    /**
     * All printings of the same card (including this one) — i.e. the variants
     * sharing this item's base_key. Lets a UI collapse printings under a base card.
     *
     * @return HasMany<CatalogItem, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'base_key', 'base_key');
    }

    /** @return HasMany<SaleObservation, $this> */
    public function saleObservations(): HasMany
    {
        return $this->hasMany(SaleObservation::class);
    }

    /**
     * Every computed priced state (raw conditions + graded company/grade).
     *
     * @return HasMany<MarketValue, $this>
     */
    public function marketValues(): HasMany
    {
        return $this->hasMany(MarketValue::class);
    }

    /** Human display name that distinguishes printings (edition/variant/error). */
    public function getDisplayNameAttribute(): string
    {
        // NB: read via getAttribute — the JSON column is named `attributes`, which
        // collides with Eloquent's internal bag when accessed as $this->attributes.
        return CardDisplayName::for($this->name, $this->getAttribute('attributes') ?? []);
    }

    /**
     * The headline ungraded value for list display — near-mint single, or the
     * sealed value for sealed product. Null when no observations exist.
     *
     * @return HasOne<MarketValue, $this>
     */
    public function defaultMarketValue(): HasOne
    {
        return $this->hasOne(MarketValue::class)
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->orderBy('id');
    }

    /**
     * Restrict a query to one representative row per base card (for "group by base"
     * reads). Pair with ->get() then load ->variants() as needed.
     *
     * @param  Builder<CatalogItem>  $query
     */
    public function scopeDistinctBases(Builder $query): void
    {
        $query->groupBy('base_key');
    }
}
