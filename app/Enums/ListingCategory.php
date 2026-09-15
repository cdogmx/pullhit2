<?php

namespace App\Enums;

/**
 * What a marketplace listing is selling. Deliberately coarser than ItemType:
 * a buyer filters by "graded slab" or "raw single", which ItemType does not
 * distinguish — both are `single` in the catalog, separated only by whether a
 * grade is attached.
 */
enum ListingCategory: string
{
    case RawSingle = 'raw_single';
    case GradedSlab = 'graded_slab';
    case Sealed = 'sealed';
    case Lot = 'lot';

    public function label(): string
    {
        return match ($this) {
            self::RawSingle => 'Raw single',
            self::GradedSlab => 'Graded slab',
            self::Sealed => 'Sealed',
            self::Lot => 'Lot',
        };
    }

    /** Whether this category expects a grading company, grade and cert. */
    public function isGraded(): bool
    {
        return $this === self::GradedSlab;
    }

    /** Whether a raw condition (NM/LP/…) applies. A slab's grade replaces it. */
    public function hasCondition(): bool
    {
        return $this === self::RawSingle || $this === self::Lot;
    }
}
