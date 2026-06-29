<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A watched Amazon product. The checker (App\Actions\Alerts\CheckStockAlerts)
 * polls each due alert via Oxylabs and tweets when it's in stock at/below
 * `target_price`. Prices are integer cents. See App\Support\Amazon\AmazonProductClient.
 */
#[Fillable([
    'label',
    'asin',
    'domain',
    'geo_location',
    'target_price',
    'currency',
    'check_interval_minutes',
    'is_active',
])]
class StockAlert extends Model
{
    /** In-memory defaults so a new model matches the DB defaults before reload. */
    protected $attributes = [
        'domain' => 'com',
        'currency' => 'USD',
        'check_interval_minutes' => 15,
        'is_active' => true,
        'last_in_stock' => false,
        'last_qualified' => false,
    ];

    protected function casts(): array
    {
        return [
            'target_price' => 'integer',
            'last_price' => 'integer',
            'check_interval_minutes' => 'integer',
            'is_active' => 'boolean',
            'last_in_stock' => 'boolean',
            'last_qualified' => 'boolean',
            'last_checked_at' => 'datetime',
            'last_tweeted_at' => 'datetime',
        ];
    }

    /** Active alerts whose throttle window has elapsed (or never checked). */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('is_active', true)->where(function (Builder $q) {
            $q->whereNull('last_checked_at')
                ->orWhereRaw('last_checked_at <= date_sub(now(), interval check_interval_minutes minute)');
        });
    }

    /** The canonical product URL (optionally tagged for Amazon Associates). */
    public function productUrl(): string
    {
        $url = "https://www.amazon.{$this->domain}/dp/{$this->asin}";
        $tag = config('services.amazon.associate_tag');

        return $tag ? $url.'?tag='.urlencode($tag) : $url;
    }

    public function dueForCheck(?Carbon $now = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->last_checked_at === null) {
            return true;
        }

        $now ??= Carbon::now();

        return $this->last_checked_at->copy()
            ->addMinutes($this->check_interval_minutes)
            ->lte($now);
    }
}
