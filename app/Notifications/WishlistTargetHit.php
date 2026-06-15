<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Emails a user when one or more wishlisted cards drop to/below their target
 * price. Sent once per crossing (see CheckWishlistTargetsCommand) — not daily.
 */
class WishlistTargetHit extends Notification
{
    use Queueable;

    /**
     * @param  Collection<int, \App\Models\WishlistItem>  $items  newly-hit wishlist items
     */
    public function __construct(public Collection $items) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->items->count();

        $mail = (new MailMessage)
            ->subject($count === 1
                ? 'A wishlist card hit your target price'
                : "{$count} wishlist cards hit your target price")
            ->greeting('Good news!')
            ->line($count === 1
                ? 'A card on your wishlist just dropped to your target:'
                : 'These wishlist cards just dropped to your target:');

        foreach ($this->items as $item) {
            $name = $item->catalogItem?->display_name ?? 'A card';
            $current = '$'.number_format(($item->currentValue() ?? 0) / 100, 2);
            $target = '$'.number_format(($item->target_price ?? 0) / 100, 2);
            $mail->line("• **{$name}** — now {$current} (your target: {$target})");
        }

        return $mail->action('View your wishlist', url('/wishlist'));
    }
}
