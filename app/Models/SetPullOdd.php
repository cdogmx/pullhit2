<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pack odds for one rarity in one set — the probability a booster pack yields
 * a card of that rarity, with its source. Feeds the rip expected-value model.
 */
class SetPullOdd extends Model
{
    protected $fillable = [
        'set_id', 'rarity', 'per_pack_prob', 'method', 'source', 'note', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'per_pack_prob' => 'float',
            'confidence' => 'float',
        ];
    }

    /** @return BelongsTo<Set, $this> */
    public function set(): BelongsTo
    {
        return $this->belongsTo(Set::class);
    }
}
