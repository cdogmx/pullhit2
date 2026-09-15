<?php

namespace App\Models;

use Database\Factories\MarketplaceFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rating, always attached to a completed deal.
 *
 * There is no way to leave free-floating feedback, which is the only thing
 * keeping ratings from becoming a second comment section.
 */
class MarketplaceFeedback extends Model
{
    /** @use HasFactory<MarketplaceFeedbackFactory> */
    use HasFactory;

    protected $table = 'marketplace_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'verified' => 'boolean',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(MarketplaceDeal::class, 'marketplace_deal_id');
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_id');
    }

    public function ratee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ratee_id');
    }
}
