<?php

namespace App\Models;

use App\Enums\Condition;
use App\Support\Valuation\PricedStateKey;
use Database\Factories\CollectionItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's owned copies of a catalog item at one priced state (§4.5). Current
 * value is read from the matching market_value (never stored here); cost basis
 * is the sum of acquisition_lots.
 */
#[Fillable([
    'user_id',
    'catalog_item_id',
    'condition',
    'grading_company_id',
    'grade',
    'quantity',
    'is_for_sale',
    'notes',
    'folder',
])]
class CollectionItem extends Model
{
    /** @use HasFactory<CollectionItemFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'condition' => Condition::class,
            'grade' => 'float',
            'quantity' => 'integer',
            'is_for_sale' => 'boolean',
        ];
    }

    /** Canonical priced-state key — matches the market_values row for this copy. */
    public function stateKey(): string
    {
        return PricedStateKey::for($this->condition?->value, $this->grading_company_id, $this->grade);
    }

    /** Human label for this copy's state — e.g. "Near Mint", "PSA 10". */
    public function stateLabel(): string
    {
        if ($this->grading_company_id !== null) {
            $slug = $this->gradingCompany?->slug ?? 'graded';
            $grade = $this->grade !== null ? rtrim(rtrim(sprintf('%.1f', $this->grade), '0'), '.') : '';

            return trim(strtoupper($slug).' '.$grade);
        }

        return $this->condition?->label() ?? 'Ungraded';
    }

    /** Current per-unit value (cents) from the matching priced state, or null if uncomputed. */
    public function currentUnitValue(): ?int
    {
        $values = $this->catalogItem?->relationLoaded('marketValues')
            ? $this->catalogItem->marketValues
            : $this->catalogItem?->marketValues()->get();

        $match = $values?->firstWhere('state_key', $this->stateKey());

        return $match?->median;
    }

    /** Cost basis (cents) = Σ lots(unit_cost × quantity + fees). */
    public function costBasisCents(): int
    {
        $lots = $this->relationLoaded('acquisitionLots')
            ? $this->acquisitionLots
            : $this->acquisitionLots()->get();

        return (int) $lots->sum(fn (AcquisitionLot $lot) => $lot->unit_cost * $lot->quantity + $lot->fees);
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

    /** @return BelongsTo<GradingCompany, $this> */
    public function gradingCompany(): BelongsTo
    {
        return $this->belongsTo(GradingCompany::class);
    }

    /** @return HasMany<AcquisitionLot, $this> */
    public function acquisitionLots(): HasMany
    {
        return $this->hasMany(AcquisitionLot::class);
    }
}
