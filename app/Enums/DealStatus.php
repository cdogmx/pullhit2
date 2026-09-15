<?php

namespace App\Enums;

/**
 * Where a deal has got to.
 *
 * `paid`, `shipped` and `disputed` belong to the escrow path: they are what
 * Trustap tells us, mirrored here so the page can show a buyer where their money
 * is. A direct deal skips them — CardFoo has no way to know whether a direct
 * buyer paid, and inventing a state for it would be pretending otherwise.
 */
enum DealStatus: string
{
    case Proposed = 'proposed';
    case Accepted = 'accepted';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Complete = 'complete';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Proposed => 'Waiting to be accepted',
            self::Accepted => 'Agreed',
            self::Paid => 'Paid, awaiting shipment',
            self::Shipped => 'Shipped',
            self::Complete => 'Complete',
            self::Disputed => 'In dispute',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still going: the listing stays off the market while one of these is open. */
    public function isOpen(): bool
    {
        return in_array($this, [
            self::Proposed, self::Accepted, self::Paid, self::Shipped, self::Disputed,
        ], true);
    }

    /** Whether either side may still walk away without a dispute. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Proposed, self::Accepted], true);
    }
}
