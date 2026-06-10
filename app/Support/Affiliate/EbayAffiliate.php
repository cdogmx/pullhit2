<?php

namespace App\Support\Affiliate;

/**
 * Builds eBay Partner Network (EPN) affiliate links. The search link needs only
 * a campaign id (no developer keys), so it works as the always-available
 * "Shop on eBay" fallback even before the Browse API is configured.
 */
class EbayAffiliate
{
    /** EPN-tagged eBay sold/active search URL for a card query. */
    public function searchUrl(string $query): string
    {
        $c = config('services.ebay');
        $url = 'https://www.ebay.com/sch/i.html?'.http_build_query(['_nkw' => $query]);

        if (empty($c['campaign_id'])) {
            return $url;
        }

        return $url.'&'.http_build_query([
            'mkcid' => '1',
            'mkrid' => $c['rover_id'],
            'siteid' => '0',
            'campid' => $c['campaign_id'],
            'toolid' => '10001',
            'mkevt' => '1',
        ]);
    }
}
