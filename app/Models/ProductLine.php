<?php

namespace App\Models;

use Database\Factories\ProductLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A product line within a vertical (TCG: Pokémon, MTG; Sports: Basketball).
 */
#[Fillable(['vertical_id', 'slug', 'name', 'logo_path', 'description', 'metadata'])]
class ProductLine extends Model
{
    /** @use HasFactory<ProductLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Vertical, $this> */
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /** @return HasMany<Set, $this> */
    public function sets(): HasMany
    {
        return $this->hasMany(Set::class);
    }

    /** @return HasMany<CatalogItem, $this> */
    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }
}
