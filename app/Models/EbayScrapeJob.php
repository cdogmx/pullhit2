<?php

namespace App\Models;

use Database\Factories\EbayScrapeJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sold search for the browser agent to run.
 *
 * Leases, not assignments: the worker is a browser extension on someone's
 * desktop, so it can stop existing between claiming a job and reporting on it.
 * A lease that runs out puts the job back in the queue rather than stranding it.
 */
class EbayScrapeJob extends Model
{
    /** @use HasFactory<EbayScrapeJobFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_LEASED = 'leased';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** The agent reached eBay and was refused — a fact about us, not the card. */
    public const STATUS_BLOCKED = 'blocked';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'leased_until' => 'datetime',
            'completed_at' => 'datetime',
            'priority' => 'integer',
            'attempts' => 'integer',
            'comps_found' => 'integer',
        ];
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class);
    }

    /**
     * Jobs available to be claimed: never started, or leased to an agent that
     * went away without reporting back.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', self::STATUS_PENDING)
            ->orWhere(fn (Builder $stale) => $stale
                ->where('status', self::STATUS_LEASED)
                ->where('leased_until', '<', now())));
    }

    /** Still to do — the number the toggle shows. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_LEASED]);
    }
}
