<?php

namespace App\Support\Ebay;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Parses eBay search-results HTML (the current "s-card" component) into raw sold
 * candidates. Isolated + regex-based (no DOM dependency); tuned to eBay's markup,
 * so keep it swappable if eBay changes. Promo "Shop on eBay" cards are skipped.
 *
 * Splits on <li> boundaries so each card's sold-date caption, title, price, and
 * seller are read from the SAME block — eBay renders the "Sold <date>" caption
 * BEFORE the title link, so a title-anchored window would mis-attribute dates.
 */
final class EbayHtmlParser
{
    /**
     * @return array<int, SoldCandidate>
     */
    public static function parse(string $html): array
    {
        $out = [];

        foreach (preg_split('/(?=<li[\s>])/i', $html) ?: [] as $block) {
            $candidate = self::parseBlock($block);

            if ($candidate !== null) {
                $out[] = $candidate;
            }
        }

        return $out;
    }

    private static function parseBlock(string $block): ?SoldCandidate
    {
        // Title link (s-card__link wrapping s-card__title). Attribute quotes are
        // optional — eBay's live markup quotes them; older fixtures don't. The
        // image link (also s-card__link) wraps an <img>, not the title div, so
        // it never matches.
        if (! preg_match(
            '#<a class=["\']?s-card__link[^>]*\bhref=["\']?([^\s"\'>]+)[^>]*>\s*<div[^>]*class=["\']?s-card__title[^>]*>(.*?)</div>#s',
            $block,
            $m,
        )) {
            return null;
        }

        $title = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5);
        // Strip eBay's "New Listing" prefix and "Opens in a new window" a11y suffix.
        $title = preg_replace('/^\s*New Listing/i', '', $title);
        $title = trim((string) preg_replace('/\s*Opens in a new window.*$/is', '', $title));

        if ($title === '' || stripos($title, 'Shop on eBay') !== false) {
            return null;
        }

        if (! preg_match('#/itm/(\d+)#', $m[1], $idMatch) || $idMatch[1] === '123456') {
            return null;
        }
        $itemId = $idMatch[1];

        // Sold price (first amount in a price span).
        if (! preg_match('#s-card__price[^>]*>\s*\$?([\d,]+\.\d{2})#', $block, $priceMatch)) {
            return null;
        }
        $priceCents = (int) round((float) str_replace(',', '', $priceMatch[1]) * 100);
        if ($priceCents <= 0) {
            return null;
        }

        $soldAt = null;
        if (preg_match('#Sold\s+([A-Z][a-z]{2}\s+\d{1,2},?\s+\d{4})#', $block, $dateMatch)) {
            try {
                $soldAt = CarbonImmutable::parse($dateMatch[1]);
            } catch (Throwable) {
                $soldAt = null;
            }
        }

        // Seller: "<username> <pct>% positive (<feedback>)" in the secondary
        // attributes. Anchor on the distinctive "% positive" span and take the
        // username span before it. Captured for single-seller down-weighting.
        $seller = null;
        if (preg_match('#<span[^>]*>\s*([A-Za-z0-9][A-Za-z0-9_.\-*]{2,})\s*</span>\s*<span[^>]*>\s*[\d.]+%\s+positive#i', $block, $sellerMatch)) {
            $seller = $sellerMatch[1];
        }

        // Gallery thumbnail — anchor on eBay's image CDN host so it survives
        // class-name churn. Upsize the s-l140 thumb to a clearer s-l500.
        $imageUrl = null;
        if (preg_match('#<img[^>]+src=["\']?(https://i\.ebayimg\.com/[^\s"\'>]+)#i', $block, $imgMatch)) {
            $imageUrl = preg_replace('#/s-l\d+\.#', '/s-l500.', $imgMatch[1]);
        }

        return new SoldCandidate($title, $priceCents, $soldAt, $itemId, "https://www.ebay.com/itm/{$itemId}", $seller, $imageUrl);
    }
}
