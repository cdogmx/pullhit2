<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One-time onboarding email, sent the moment a new user verifies their email
 * (see App\Listeners\SendWelcomeEmail). Transactional — always delivered,
 * not gated by notification preferences. Also drops a greeting into the
 * in-app notification center.
 */
class WelcomeNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
            'title' => 'Welcome to CardFoo — wax on!',
            'body' => 'Scan a card to start building your collection.',
            'url' => '/scan',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to CardFoo — wax on!')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line('Thanks for joining CardFoo — your email is verified and your collection is ready to go.')
            ->line('Scan a card to track its value, build your collection and wishlist, and climb the community rankings by helping improve the catalog.')
            ->action('Scan your first card', url('/scan'))
            ->line('Wax on. 🥋');
    }
}
