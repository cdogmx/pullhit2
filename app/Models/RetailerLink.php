<?php

namespace App\Models;

use App\Enums\Retailer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One retailer (store) we poll for a tracked product. Carries its own URL /
 * external id and the last polled stock/price state so the checker can fire on
 * the rising edge per retailer. See App\Actions\Alerts\CheckStockAlerts.
 */
#[Fillable([
    'tracked_product_id',
    'retailer',
    'url',
    'external_id',
    'is_active',
])]
class RetailerLink extends Model
{
    protected $attributes = [
        'is_active' => true,
        'last_in_stock' => false,
        'last_qualified' => false,
    ];

    protected function casts(): array
    {
        return [
            'retailer' => Retailer::class,
            'is_active' => 'boolean',
            'last_price' => 'integer',
            'last_in_stock' => 'boolean',
            'last_qualified' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_tweeted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrackedProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(TrackedProduct::class, 'tracked_product_id');
    }

    /** Active links on an active product whose throttle window has elapsed. */
    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $p) => $p->where('is_active', true))
            ->where(function (Builder $q) {
                $q->whereNull('last_checked_at')->orWhereRaw(
                    'last_checked_at <= date_sub(now(), interval (select check_interval_minutes from tracked_products where tracked_products.id = retailer_links.tracked_product_id) minute)'
                );
            });
    }

    public function dueForCheck(?Carbon $now = null): bool
    {
        if (! $this->is_active || ! $this->product?->is_active) {
            return false;
        }

        if ($this->last_checked_at === null) {
            return true;
        }

        $now ??= Carbon::now();

        return $this->last_checked_at->copy()
            ->addMinutes($this->product->check_interval_minutes)
            ->lte($now);
    }
}
