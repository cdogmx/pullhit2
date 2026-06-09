<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user monthly scan tally (cards identified). One row per (user, period);
 * drives the free/premium scan cap. `period` is "YYYY-MM".
 */
#[Fillable([
    'user_id',
    'period',
    'count',
])]
class ScanUsage extends Model
{
    protected function casts(): array
    {
        return [
            'count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
