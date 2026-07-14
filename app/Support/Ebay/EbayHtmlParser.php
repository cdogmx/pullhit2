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

    /**
     * Whether eBay EXPLICITLY reported zero matches — the "0 results" / "no exact
     * matches found" messaging. Distinct from an empty parse of a degraded render
     * or an anti-bot interstitial (which have no such message): a caller that
     * parses nothing can trust this as a real zero and stop retrying.
     */
    public static function isEmptyResults(string $html): bool
    {
        return preg_match('/\bno (?:exact )?match(?:es)? found\b/i', $html) === 1
            || preg_match('/\b0 results\b/i', $html) === 1
            || (bool) preg_match('/\bnothing\b[^<]{0,40}\bmatch(?:ed|es)?\b/i', $html);
    }

    private static function parseBlock(string $block): ?SoldCandidate
    {
        // Title + listing link. eBay serves two layouts interchangeably — the
        // current "s-card" component and the legacy "s-item" one — so we try
        // both. Attribute quotes are optional (live markup quotes them; older
        // fixtures don't). The image link shares the class but wraps an <img>,
        // not the title, so it never matches.
        [$href, $titleHtml] = self::titleAndHref($block);
        if ($href === null) {
            return null;
        }

        $title = html_entity_decode(strip_tags($titleHtml), ENT_QUOTES | ENT_HTML5);
        // Strip eBay's "New Listing" prefix and "Opens in a new window" a11y suffix.
        $title = preg_replace('/^\s*New Listing/i', '', $title);
        $title = trim((string) preg_replace('/\s*Opens in a new window.*$/is', '', $title));

        if ($title === '' || stripos($title, 'Shop on eBay') !== false) {
            return null;
        }

        if (! preg_match('#/itm/(\d+)#', $href, $idMatch) || $idMatch[1] === '123456') {
            return null;
        }
        $itemId = $idMatch[1];

        // Sold price — the first "$n.nn" after the price span, allowing a nested
        // wrapper span (legacy s-item nests the amount in an <span class=ITALIC>).
        if (! preg_match('#s-(?:card|item)__price\b.{0,80}?\$([\d,]+\.\d{2})#s', $block, $priceMatch)) {
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
        // attributes (s-card). Anchor on the distinctive "% positive" span and
        // take the username span before it. Captured for single-seller
        // down-weighting. Fall back to the legacy s-item seller-info span.
        $seller = null;
        if (preg_match('#<span[^>]*>\s*([A-Za-z0-9][A-Za-z0-9_.\-*]{2,})\s*</span>\s*<span[^>]*>\s*[\d.]+%\s+positive#i', $block, $sellerMatch)) {
            $seller = $sellerMatch[1];
        } elseif (preg_match('#s-item__seller-info-text[^>]*>\s*([A-Za-z0-9][A-Za-z0-9_.\-*]{2,})\b#i', $block, $sellerMatch)) {
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

    /**
     * The listing's (href, title-HTML) from either eBay layout — the current
     * "s-card" component or the legacy "s-item" one — or [null, null] when the
     * block is neither (image-only sub-block, promo, divider). The title HTML is
     * returned raw for the caller to strip/clean.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function titleAndHref(string $block): array
    {
        // Current layout: the title link wraps the title div directly.
        if (preg_match(
            '#<a class=["\']?s-card__link[^>]*\bhref=["\']?([^\s"\'>]+)[^>]*>\s*<div[^>]*class=["\']?s-card__title[^>]*>(.*?)</div>#s',
            $block,
            $m,
        )) {
            return [$m[1], $m[2]];
        }

        // Legacy layout: the link (s-item__link) and title (s-item__title) are
        // separate nodes in the same <li>; the title sits inside its own div.
        if (preg_match('#class=["\']?s-item__link[^>]*\bhref=["\']?([^\s"\'>]+)#', $block, $hrefMatch)
            && preg_match('#class=["\']?s-item__title[^>]*>(.*?)</(?:div|h3)>#s', $block, $titleMatch)) {
            return [$hrefMatch[1], $titleMatch[1]];
        }

        return [null, null];
    }
}
