<?php

namespace App\Enums;

/**
 * Where a marketplace listing is in its life.
 *
 * `pending` is the state that matters: a deal has been logged against the
 * listing but not completed, so it must stop appearing in browse without being
 * destroyed — the deal can still fall through and put it back on sale.
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Pending = 'pending';
    case Sold = 'sold';
    case Expired = 'expired';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Pending => 'Deal in progress',
            self::Sold => 'Sold',
            self::Expired => 'Expired',
            self::Removed => 'Removed',
        };
    }

    /** The states a buyer can see in browse. */
    public static function public(): array
    {
        return [self::Active];
    }

    /** Whether the seller can still edit it. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Active, self::Expired], true);
    }
}
