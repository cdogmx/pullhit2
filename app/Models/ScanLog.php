<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded scan request — see the migration for the audit-log rationale.
 */
class ScanLog extends Model
{
    /** @use HasFactory<\Database\Factories\ScanLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mode',
        'cards',
        'ai_reads',
        'cache_hits',
        'credits_spent',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
