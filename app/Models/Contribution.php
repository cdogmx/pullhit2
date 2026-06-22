<?php

namespace App\Models;

use App\Enums\ContributionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One accepted contribution worth points — the points ledger. Append-only.
 */
#[Fillable(['user_id', 'type', 'points', 'description'])]
class Contribution extends Model
{
    protected function casts(): array
    {
        return [
            'type' => ContributionType::class,
            'points' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
