<?php

namespace App\Support\Membership;

use App\Exceptions\TooManyScansException;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Per-user monthly scan allowance, metered by CARDS identified. The cap comes
 * from Entitlements (admins are unlimited). Usage persists in scan_usages so it
 * survives a cache flush and is auditable.
 */
class ScanQuota
{
    public function __construct(protected User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public function cap(): int
    {
        return Entitlements::for($this->user)->scanCap();
    }

    public function period(): string
    {
        return Carbon::now()->format('Y-m');
    }

    public function used(): int
    {
        return (int) ($this->user->scanUsages()->where('period', $this->period())->value('count') ?? 0);
    }

    /** Purchased (top-up) credits that persist past the monthly allowance. */
    public function credits(): int
    {
        return (int) $this->user->purchased_scan_credits;
    }

    /** Allowance left this month (PHP_INT_MAX for unlimited/admin). */
    public function monthlyRemaining(): int
    {
        $cap = $this->cap();

        return $cap === PHP_INT_MAX ? PHP_INT_MAX : max(0, $cap - $this->used());
    }

    /** Total scans left = monthly allowance + purchased credits. */
    public function remaining(): int
    {
        $monthly = $this->monthlyRemaining();

        return $monthly === PHP_INT_MAX ? PHP_INT_MAX : $monthly + $this->credits();
    }

    /** Throw when the user has no scans left (admins never do). */
    public function ensure(): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($this->remaining() <= 0) {
            throw new TooManyScansException($this->cap());
        }
    }

    /**
     * Meter N AI card reads: spend the monthly allowance first, then draw any
     * overflow from purchased credits. Cache-recognized scans aren't passed here.
     * Returns the number of purchased credits spent (for the scan log).
     */
    public function record(int $cards): int
    {
        if ($cards <= 0) {
            return 0;
        }

        $monthly = $this->monthlyRemaining();
        if ($monthly === PHP_INT_MAX) {
            return 0; // unlimited (admin) — nothing to meter
        }

        $fromMonthly = min($cards, $monthly);
        if ($fromMonthly > 0) {
            $usage = $this->user->scanUsages()->firstOrCreate(['period' => $this->period()], ['count' => 0]);
            $usage->increment('count', $fromMonthly);
        }

        $overflow = $cards - $fromMonthly;
        $creditsSpent = 0;
        if ($overflow > 0 && $this->credits() > 0) {
            $creditsSpent = min($overflow, $this->credits());
            $this->user->decrement('purchased_scan_credits', $creditsSpent);
        }

        return $creditsSpent;
    }

    /**
     * @return array{used: int, cap: int|null, remaining: int|null, monthly_remaining: int|null, credits: int, unlimited: bool}
     */
    public function snapshot(): array
    {
        $cap = $this->cap();
        $unlimited = $cap === PHP_INT_MAX;

        return [
            'used' => $this->used(),
            'cap' => $unlimited ? null : $cap,
            'remaining' => $unlimited ? null : $this->remaining(),
            'monthly_remaining' => $unlimited ? null : $this->monthlyRemaining(),
            'credits' => $this->credits(),
            'unlimited' => $unlimited,
        ];
    }
}
