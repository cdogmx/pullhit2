<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Verified;

/**
 * Sends the welcome email once a user confirms their address. Fortify fires
 * Verified after marking the email verified, so this runs exactly once per
 * account. Auto-discovered via the typed handle() signature.
 */
class SendWelcomeEmail
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        if ($user instanceof User) {
            $user->notify(new WelcomeNotification);
        }
    }
}
