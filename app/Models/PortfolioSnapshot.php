<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day's portfolio value + cost basis for a user. Written by the
 * valuation:snapshot command; read for the dashboard "value over time" chart.
 */
#[Fillable([
    'user_id', 'total_value_cents', 'cost_basis_cents', 'card_count', 'captured_on',
])]
class PortfolioSnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'total_value_cents' => 'integer',
            'cost_basis_cents' => 'integer',
            'card_count' => 'integer',
            'captured_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
