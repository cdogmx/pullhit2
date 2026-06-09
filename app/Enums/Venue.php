<?php

namespace App\Enums;

/**
 * Where a sale/price observation came from (§4.4). Used to apply per-venue bias
 * priors before blending (e.g. eBay "Sold For" can run hot vs. actual paid).
 */
enum Venue: string
{
    case TCGplayer = 'tcgplayer';
    case Ebay = 'ebay';
    case Whatnot = 'whatnot';
    case OwnMarketplace = 'own_marketplace';
    case Other = 'other';
}
