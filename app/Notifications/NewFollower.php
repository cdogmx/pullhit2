<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Tells a user someone started following them. In-app only — a follow isn't
 * worth an email — so it never touches the mail channel or a preference.
 */
class NewFollower extends Notification
{
    use Queueable;

    public function __construct(public User $follower) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_follower',
            'title' => "@{$this->follower->username} followed you",
            'body' => 'You have a new follower on CardFoo.',
            'url' => "/u/{$this->follower->username}",
        ];
    }
}
