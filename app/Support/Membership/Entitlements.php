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

    public function isAdmin(): bool
    {
        return $this->user->isAdmin();
    }

    /** Monthly scan allowance (cards identified). Admins are unlimited. */
    public function scanCap(): int
    {
        if ($this->isAdmin()) {
            return PHP_INT_MAX;
        }

        return (int) config("membership.scan_caps.{$this->tier()->value}", 0);
    }

    /** Max named collections this tier allows (PHP_INT_MAX = unlimited). */
    public function collectionLimit(): int
    {
        return $this->limit('collections');
    }

    /** Max wishlists this tier allows. */
    public function wishlistLimit(): int
    {
        return $this->limit('wishlists');
    }

    /** Max active target-price alerts this tier allows. */
    public function alertLimit(): int
    {
        return $this->limit('alerts');
    }

    /** A per-tier list limit from config; -1 (or admin) means unlimited. */
    private function limit(string $key): int
    {
        if ($this->isAdmin()) {
            return PHP_INT_MAX;
        }

        $value = (int) config("membership.limits.{$key}.{$this->tier()->value}", 1);

        return $value < 0 ? PHP_INT_MAX : $value;
    }

    public function can(string $feature): bool
    {
        if ($this->isAdmin()) {
            return true; // admins have unlimited access to all features
        }

        return match ($feature) {
            // Always-free in Phase 4a.
            'collection', 'portfolio' => true,
            // Everything else is premium-only by default.
            default => $this->isPremium(),
        };
    }
}
