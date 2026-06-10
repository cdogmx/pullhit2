<?php

namespace App\Support\Affiliate;

/**
 * Builds TCGplayer search links with affiliate UTM params when a partner id is
 * configured (plain search otherwise). The partner env is a placeholder for the
 * real Impact tracking template, which can be swapped in later.
 */
class TcgplayerAffiliate
{
    public function searchUrl(string $query): string
    {
        $partner = config('services.tcgplayer.partner');

        $params = ['q' => $query, 'productLineName' => 'pokemon'];
        if (! empty($partner)) {
            $params += [
                'utm_campaign' => 'affiliate',
                'utm_medium' => 'affiliate',
                'utm_source' => $partner,
                'partner' => $partner,
            ];
        }

        return 'https://www.tcgplayer.com/search/all/product?'.http_build_query($params);
    }
}
