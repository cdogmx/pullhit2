<?php

namespace App\Enums;

/**
 * Raw (ungraded) condition of a physical copy. Applies to instances — owned
 * copies, observed sales, listings — never to the abstract catalog_item (§4.3).
 * Scale matches TCGplayer/JustTCG for clean joins; SEALED is used for sealed product.
 *
 * Defined in Phase 1 for reuse by the Phase 3/4 instance tables; not yet
 * attached to any table.
 */
enum Condition: string
{
    case NearMint = 'NM';
    case LightlyPlayed = 'LP';
    case ModeratelyPlayed = 'MP';
    case HeavilyPlayed = 'HP';
    case Damaged = 'DMG';
    case Sealed = 'SEALED';
}
