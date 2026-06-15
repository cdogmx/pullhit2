<?php

namespace App\Console\Commands;

use App\Models\WishlistItem;
use App\Notifications\WishlistTargetHit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Alert users when a wishlisted card's market value drops to/below its target.
 * `target_hit_at` is set on the crossing so we email once, not every day; it
 * clears when the price recovers above target (re-arming the alert).
 */
class CheckWishlistTargetsCommand extends Command
{
    protected $signature = 'wishlist:check-targets';

    protected $description = 'Email users when a wishlisted card drops to/below its target price.';

    public function handle(): int
    {
        $items = WishlistItem::query()
            ->whereNotNull('target_price')
            ->with(['user', 'catalogItem.defaultMarketValue'])
            ->get();

        $newlyHit = collect();

        foreach ($items as $item) {
            $current = $item->currentValue();
            if ($current === null) {
                continue;
            }

            $atTarget = $current <= $item->target_price;

            if ($atTarget && $item->target_hit_at === null) {
                $item->forceFill(['target_hit_at' => Carbon::now()])->save();
                $newlyHit->push($item);
            } elseif (! $atTarget && $item->target_hit_at !== null) {
                $item->forceFill(['target_hit_at' => null])->save(); // re-arm
            }
        }

        $byUser = $newlyHit->groupBy('user_id');

        foreach ($byUser as $userItems) {
            $user = $userItems->first()->user;
            if ($user && $user->email_verified_at) {
                $user->notify(new WishlistTargetHit($userItems));
            }
        }

        $this->info("Checked {$items->count()} targets — {$newlyHit->count()} newly hit, notified {$byUser->count()} users.");

        return self::SUCCESS;
    }
}
