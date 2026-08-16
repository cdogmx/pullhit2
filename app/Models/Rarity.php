<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How one source rarity string is presented in the filter.
 *
 * A row per raw value, never a merge: "Art Rare" (JP) and "Illustration Rare"
 * (EN) are the same slot in two markets, not the same card, so each keeps its
 * own label and simply sits next to the other in the order.
 */
class Rarity extends Model
{
    protected $fillable = ['value', 'label', 'sort_order', 'is_hidden'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_hidden' => 'boolean',
        ];
    }
}
