<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's report that a card or set is missing from the catalog, awaiting
 * admin review. Accepted reports earn the submitter contribution points.
 */
#[Fillable(['user_id', 'kind', 'name', 'details', 'status', 'reviewed_by', 'review_note', 'reviewed_at'])]
class CardReport extends Model
{
    protected function casts(): array
    {
        return [
            'details' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
