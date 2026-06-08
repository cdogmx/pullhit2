<?php

namespace App\Enums;

/**
 * The kind of thing a catalog_item represents. Vertical-agnostic: a "single" is
 * one card/unit, "sealed" is sealed product (booster box, ETB, pack), "lot" is
 * a bundle of items, "other" is anything else a vertical needs.
 */
enum ItemType: string
{
    case Single = 'single';
    case Sealed = 'sealed';
    case Lot = 'lot';
    case Other = 'other';
}
