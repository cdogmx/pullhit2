<?php

namespace App\Models;

use Database\Factories\VerticalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A collectible vertical (tcg, sports, other) — top of the catalog hierarchy.
 * Behaviour is described by the Vertical registry + seed data, not columns.
 */
#[Fillable(['slug', 'name', 'config'])]
class Vertical extends Model
{
    /** @use HasFactory<VerticalFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    /** @return HasMany<ProductLine, $this> */
    public function productLines(): HasMany
    {
        return $this->hasMany(ProductLine::class);
    }
}
