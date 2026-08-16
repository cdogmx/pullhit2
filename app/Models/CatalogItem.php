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
    'msrp_source',
    'released_at',
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
            'popularity' => 'integer',
            'last_viewed_at' => 'datetime',
            'ebay_refreshed_at' => 'datetime',
            'for_sale_refreshed_at' => 'datetime',
            'pc_synced_at' => 'datetime',
        ];
    }

    /**
     * Long-term monthly price history from PriceCharting, normalized to a
     * grade-tier map: {"ungraded": [...], "9": [...], "10": [...]}. Transparently
     * upgrades the legacy flat-array shape (a bare ungraded series).
     *
     * @return array<string, array<int, array{t: string, price: int}>>
     */
    public function longTermHistoryTiers(): array
    {
        $h = $this->priceHistory?->history;
        if (empty($h) || ! is_array($h)) {
            return [];
        }

        // Legacy shape: a list of {t, price} points === the ungraded series.
        return array_is_list($h) ? ['ungraded' => $h] : $h;
    }

    /**
     * The PriceCharting long-term series. Its own table because it averaged ~1 KB
     * a row and was being selected by every list query that never reads it.
     *
     * @return HasOne<CatalogItemPriceHistory, $this>
     */
    public function priceHistory(): HasOne
    {
        return $this->hasOne(CatalogItemPriceHistory::class);
    }

    /**
     * One grade tier's long-term series ("ungraded" by default).
     *
     * @return array<int, array{t: string, price: int}>
     */
    public function longTermHistory(string $tier = 'ungraded'): array
    {
        return $this->longTermHistoryTiers()[$tier] ?? [];
    }

    protected static function booted(): void
    {
        // New cards get a URL slug, unique within their set.
        static::creating(function (CatalogItem $item) {
            if (empty($item->slug)) {
                $item->slug = $item->buildUniqueSlug();
            }
        });

        // The slug is derived from the card's name and number, so correcting
        // either moves its URL. Keep the old one so existing links redirect
        // rather than 404 — a correction should never cost the page its inbound
        // links. Recorded after the write so a failed save leaves no alias.
        static::updated(function (CatalogItem $item) {
            $old = $item->getOriginal('slug');

            if (! $item->wasChanged('slug') || empty($old) || empty($item->set_id)) {
                return;
            }

            CatalogItemSlugAlias::updateOrCreate(
                ['set_id' => $item->set_id, 'slug' => $old],
                ['catalog_item_id' => $item->getKey()],
            );

            // The card just reclaimed a slug it previously vacated; that alias
            // would now redirect the card to itself.
            CatalogItemSlugAlias::where('set_id', $item->set_id)
                ->where('slug', $item->slug)
                ->delete();
        });
    }

    /** @return HasMany<CatalogItemSlugAlias, $this> */
    public function slugAliases(): HasMany
    {
        return $this->hasMany(CatalogItemSlugAlias::class);
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

    /** @return HasMany<ListingObservation, $this> */
    public function listingObservations(): HasMany
    {
        return $this->hasMany(ListingObservation::class);
    }

    /**
     * The newest REAL (non-synthetic, non-outlier) sold comp for EACH priced
     * state, keyed by its market_values state_key ("NM", "psa-10", …) — the "last
     * sold" signal per condition/grade, so the card page can show it for whichever
     * state the chart dropdown selects. States with no real sale are absent.
     *
     * @return array<string, array{price: int, sold_at: string, venue: string}>
     */
    public function lastSalesByState(): array
    {
        $companies = GradingCompany::pluck('slug', 'id'); // id => slug (no N+1)

        $out = [];
        $observations = $this->saleObservations()
            ->where('is_synthetic', false)
            ->where('is_outlier', false)
            // eBay sweeps store observed_at as date-only, so on the newest day the
            // ties must break by ingest order (created_at, then id) — otherwise
            // "last sold" is whichever same-day row the DB happens to return first
            // (it was surfacing an early, high sale over the genuinely latest one).
            ->orderByDesc('observed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'price', 'observed_at', 'created_at', 'venue', 'condition', 'grading_company_id', 'grade']);

        foreach ($observations as $o) {
            // The same key market_values uses (PricedStateKey), built inline off
            // the preloaded company slugs.
            $key = $o->grading_company_id !== null
                ? ($companies[$o->grading_company_id] ?? "company{$o->grading_company_id}")
                    .'-'.($o->grade !== null ? rtrim(rtrim(sprintf('%.1f', (float) $o->grade), '0'), '.') : '?')
                : ($o->condition?->value ?? 'raw');

            // Newest-first, so the first row for a state is its last sale.
            $out[$key] ??= [
                'price' => (int) $o->price,
                'sold_at' => $o->observed_at->toIso8601String(),
                'venue' => $o->venue->value,
            ];
        }

        return $out;
    }

    /**
     * Deals-tracker products watching this card for in-stock-at-target offers —
     * the single source of "where to buy" links (retailer, live price, stock).
     *
     * @return HasMany<TrackedProduct, $this>
     */
    public function trackedProducts(): HasMany
    {
        return $this->hasMany(TrackedProduct::class);
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
     * A graded priced state — constrained per query when browsing by grade/grader
     * (SearchCatalog narrows it to the requested company/grade). Base is any
     * graded row, richest first.
     *
     * @return HasOne<MarketValue, $this>
     */
    public function gradedMarketValue(): HasOne
    {
        return $this->hasOne(MarketValue::class)
            ->whereNotNull('grading_company_id')
            ->orderByDesc('grade')
            ->orderByDesc('median');
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
