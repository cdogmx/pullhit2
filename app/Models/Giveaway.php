<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A monthly community giveaway. A user's entries are the points they earned in
 * the giveaway's `period` (YYYY-MM) — see App\Actions\Community\DrawGiveaway.
 */
#[Fillable(['period', 'title', 'prize', 'image_path', 'description', 'status'])]
class Giveaway extends Model
{
    public const OPEN = 'open';

    public const DRAWN = 'drawn';

    protected function casts(): array
    {
        return [
            'drawn_at' => 'datetime',
            'winner_entries' => 'integer',
            'total_entries' => 'integer',
            'entrant_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_user_id');
    }

    /** The open giveaway for the current calendar month, if any. */
    public static function current(): ?self
    {
        return static::where('period', Carbon::now()->format('Y-m'))
            ->where('status', self::OPEN)
            ->first();
    }

    /** @param  Builder<Giveaway>  $query */
    public function scopeDrawn(Builder $query): void
    {
        $query->where('status', self::DRAWN);
    }

    /** Inclusive period bounds for summing contribution points. */
    public function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->period)->startOfMonth();
    }

    public function periodEnd(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->period)->endOfMonth();
    }

    /** Human label, e.g. "June 2026". */
    public function periodLabel(): string
    {
        return Carbon::createFromFormat('Y-m', $this->period)->format('F Y');
    }
}
