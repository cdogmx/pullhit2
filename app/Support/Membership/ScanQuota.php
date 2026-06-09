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

    public function remaining(): int
    {
        return max(0, $this->cap() - $this->used());
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

    /** Record N identified cards against the current period (atomic upsert). */
    public function record(int $cards): void
    {
        if ($cards <= 0) {
            return;
        }

        $usage = $this->user->scanUsages()->firstOrCreate(['period' => $this->period()], ['count' => 0]);
        $usage->increment('count', $cards);
    }

    /** @return array{used: int, cap: int|null, remaining: int|null, unlimited: bool} */
    public function snapshot(): array
    {
        $cap = $this->cap();
        $unlimited = $cap === PHP_INT_MAX;

        return [
            'used' => $this->used(),
            'cap' => $unlimited ? null : $cap,
            'remaining' => $unlimited ? null : $this->remaining(),
            'unlimited' => $unlimited,
        ];
    }
}
