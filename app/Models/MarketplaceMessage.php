<?php

namespace App\Models;

use Database\Factories\MarketplaceMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a thread. Free text; see the migration for why. */
class MarketplaceMessage extends Model
{
    /** @use HasFactory<MarketplaceMessageFactory> */
    use HasFactory;

    protected $guarded = [];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(MarketplaceThread::class, 'marketplace_thread_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
