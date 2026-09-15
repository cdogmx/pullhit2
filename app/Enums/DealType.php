<?php

namespace App\Enums;

/**
 * How a deal is being paid for, which decides what its completion is worth.
 *
 * Direct is self-reported: two people say it happened. Escrow is attested by
 * Trustap, who held the money. Both count, and the reputation model shows them
 * separately rather than adding them together, because one can be manufactured
 * between friends and the other cannot.
 */
enum DealType: string
{
    case Direct = 'direct';
    case Escrow = 'escrow';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Escrow => 'Protected',
        };
    }

    /** Whether completing this deal produces verified feedback. */
    public function isVerified(): bool
    {
        return $this === self::Escrow;
    }
}
