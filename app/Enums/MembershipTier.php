<?php

namespace App\Enums;

/**
 * A user's membership level. Collection + portfolio are free for everyone;
 * tiers differ on scan volume (Phase 4b) and future premium features. Billing
 * is deferred — premium is granted as a plain flag for now.
 */
enum MembershipTier: string
{
    case Free = 'free';
    case Premium = 'premium';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Premium => 'Premium',
        };
    }
}
