<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One night's reading of how far our prices sit from an outside source.
 *
 * Kept as a series rather than a single current value, because the useful
 * question is not "how many cards disagree" but "is that number moving".
 */
class PriceHealthSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'buckets' => 'array',
            'compared' => 'integer',
            'over_2x' => 'integer',
            'under_half' => 'integer',
        ];
    }
}
