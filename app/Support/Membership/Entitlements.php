<?php

namespace App\Support\Membership;

use App\Enums\MembershipTier;
use App\Models\User;

/**
 * Single place that answers "what is this user allowed to do?". Collection and
 * portfolio are free for everyone; the only tier-sensitive levers today are the
 * monthly scan cap (enforced in Phase 4b) and any future premium-only features.
 */
class Entitlements
{
    public function __construct(protected User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function tier(): MembershipTier
    {
        return $this->user->membership_tier;
    }

    public function isPremium(): bool
    {
        return $this->user->isPremium();
    }

    /** Monthly scan allowance (cards identified) for this user's tier. */
    public function scanCap(): int
    {
        return (int) config("membership.scan_caps.{$this->tier()->value}", 0);
    }

    public function can(string $feature): bool
    {
        return match ($feature) {
            // Always-free in Phase 4a.
            'collection', 'portfolio' => true,
            // Everything else is premium-only by default.
            default => $this->isPremium(),
        };
    }
}
