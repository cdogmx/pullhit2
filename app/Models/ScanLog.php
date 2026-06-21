<?php

namespace App\Models;

use Database\Factories\ScanLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded scan request — see the migration for the audit-log rationale.
 */
class ScanLog extends Model
{
    /** @use HasFactory<ScanLogFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'mode',
        'image_path',
        'results',
        'cards',
        'ai_reads',
        'cache_hits',
        'credits_spent',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
