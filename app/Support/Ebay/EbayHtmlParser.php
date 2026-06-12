<?php

namespace App\Support\Ebay;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses eBay search-results HTML (the current "s-card" component) into raw sold
 * candidates. Isolated + regex-based (no DOM dependency); tuned to eBay's markup,
 * so keep it swappable if eBay changes. Promo "Shop on eBay" cards are skipped.
 */
final class EbayHtmlParser
{
    /**
     * @return array<int, SoldCandidate>
     */
    public static function parse(string $html): array
    {
        // Each listing: <a class=s-card__link ... href=...itm/{id}...>
        //   <div ... class=s-card__title>[<span>New Listing</span>]<span>TITLE</span></div></a>
        // Attribute quotes are optional (eBay's live markup quotes them; older
        // captured fixtures don't) — `["']?` matches both.
        $matched = preg_match_all(
            '#<a class=["\']?s-card__link[^>]*\bhref=["\']?([^\s"\'>]+)[^>]*>\s*<div[^>]*class=["\']?s-card__title[^>]*>(.*?)</div>\s*</a>#s',
            $html,
            $m,
            PREG_OFFSET_CAPTURE,
        );

        if (! $matched) {
            return [];
        }

        $out = [];
        $count = count($m[0]);

        for ($i = 0; $i < $count; $i++) {
            $href = $m[1][$i][0];
            $title = html_entity_decode(strip_tags($m[2][$i][0]), ENT_QUOTES | ENT_HTML5);
            // Strip eBay's "New Listing" prefix label and "Opens in a new window" a11y suffix.
            $title = preg_replace('/^\s*New Listing/i', '', $title);
            $title = trim(preg_replace('/\s*Opens in a new window.*$/is', '', $title));

            if ($title === '' || stripos($title, 'Shop on eBay') !== false) {
                continue;
            }

            if (! preg_match('#/itm/(\d+)#', $href, $idMatch) || $idMatch[1] === '123456') {
                continue;
            }

            // Canonical, tracking-free listing URL.
            $url = "https://www.ebay.com/itm/{$idMatch[1]}";

            // Window from this card's link to the next card's link.
            $start = $m[0][$i][1];
            $end = $i + 1 < $count ? $m[0][$i + 1][1] : strlen($html);
            $window = substr($html, $start, $end - $start);

            // Sold price (first amount in the price span).
            if (! preg_match('#s-card__price[^>]*>\s*\$?([\d,]+\.\d{2})#', $window, $priceMatch)) {
                continue;
            }
            $priceCents = (int) round((float) str_replace(',', '', $priceMatch[1]) * 100);
            if ($priceCents <= 0) {
                continue;
            }

            $soldAt = null;
            if (preg_match('#Sold\s+([A-Z][a-z]{2}\s+\d{1,2},?\s+\d{4})#', $window, $dateMatch)) {
                try {
                    $soldAt = CarbonImmutable::parse($dateMatch[1]);
                } catch (Throwable) {
                    $soldAt = null;
                }
            }

            // Seller: eBay renders "<username> <pct>% positive (<feedback>)" in
            // the card's secondary attributes. Anchor on the distinctive
            // "% positive" feedback span and take the username span before it.
            // Captured so the engine can down-weight single-seller-dominated comps.
            $seller = null;
            if (preg_match('#<span[^>]*>\s*([A-Za-z0-9][A-Za-z0-9_.\-*]{2,})\s*</span>\s*<span[^>]*>\s*[\d.]+%\s+positive#i', $window, $sellerMatch)) {
                $seller = $sellerMatch[1];
            }

            $out[] = new SoldCandidate($title, $priceCents, $soldAt, $idMatch[1], $url, $seller);
        }

        return $out;
    }
}
