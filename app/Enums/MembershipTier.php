<?php

namespace App\Enums;

/**
 * A user's membership level. Collection + portfolio are free for everyone; tiers
 * differ on the monthly scan-credit allowance and list limits (collections,
 * wishlists, alerts). One credit = one AI card read — cache-recognized scans are
 * free. Overflow is covered by purchased scan-credit packs.
 */
enum MembershipTier: string
{
    case Free = 'free';
    case Collector = 'collector';
    case Dealer = 'dealer';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Collector => 'Collector',
            self::Dealer => 'Dealer',
        };
    }

    /** Any paid tier (i.e. not Free). */
    public function isPaid(): bool
    {
        return $this !== self::Free;
    }
}
