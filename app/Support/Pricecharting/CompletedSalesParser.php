<?php

namespace App\Support\Pricecharting;

use App\Enums\Venue;
use Carbon\CarbonImmutable;

/**
 * Parses the completed-sales table from a PriceCharting product page (fetched
 * WITHOUT headless render — see OxylabsClient — or PriceCharting strips it). Each
 * row is `<tr id="{source}-{listingId}">` with a sale date, title (+ source tag),
 * and price. Returns one CompletedSale per row for a given condition tab.
 */
class CompletedSalesParser
{
    /**
     * @param  string  $tab  the completed-auctions tab to read (default "used" =
     *                       ungraded, which for a sealed product is the sealed sale).
     * @return array<int, CompletedSale>
     */
    public function parse(string $html, string $tab = 'used'): array
    {
        $block = $this->tabBlock($html, $tab);

        if ($block === null) {
            return [];
        }

        preg_match_all('/<tr id="(ebay|tcgplayer)-(\d+)">(.*?)<\/tr>/s', $block, $rows, PREG_SET_ORDER);

        $out = [];
        foreach ($rows as $row) {
            $source = $row[1] === 'tcgplayer' ? Venue::TCGplayer : Venue::Ebay;
            $listingId = $row[2];
            $cells = $row[3];

            $priceCents = $this->priceCents($cells);
            if ($priceCents === null) {
                continue; // no parseable sale price (e.g. blank listed-price row)
            }

            $out[] = new CompletedSale(
                source: $source,
                listingId: $listingId,
                title: $this->title($cells),
                priceCents: $priceCents,
                soldAt: $this->soldAt($cells),
                url: $this->url($cells),
            );
        }

        return $out;
    }

    /** Isolate one condition tab's container so grades don't bleed together. */
    private function tabBlock(string $html, string $tab): ?string
    {
        $start = strpos($html, 'class="completed-auctions-'.$tab.'"');
        if ($start === false) {
            return null;
        }

        // From this container to the next completed-auctions container (or end).
        $rest = substr($html, $start);
        $next = strpos($rest, 'class="completed-auctions-', 20);

        return $next === false ? $rest : substr($rest, 0, $next);
    }

    /** The sale price — the first `<td class="numeric">` with a $ amount. */
    private function priceCents(string $cells): ?int
    {
        if (! preg_match('/<td class="numeric">\s*<span class="js-price"[^>]*>\s*\$([\d,]+(?:\.\d{1,2})?)/s', $cells, $m)) {
            return null;
        }

        return (int) round((float) str_replace(',', '', $m[1]) * 100);
    }

    private function title(string $cells): string
    {
        // "</a\n>" is a legal closing tag and formatters emit it whenever the
        // anchor's attributes get wrapped, so the tag cannot be matched literally.
        if (preg_match('/<td class="title">.*?<a[^>]*>(.*?)<\/a\s*>/s', $cells, $m)) {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5);

            // The title routinely wraps across source lines; collapse the markup's
            // indentation so it reads as one line rather than carrying newlines
            // and runs of spaces into the database.
            return trim((string) preg_replace('/\s+/u', ' ', $title));
        }

        return '';
    }

    private function url(string $cells): ?string
    {
        if (preg_match('/<td class="title">.*?<a[^>]*href="([^"]*)"/s', $cells, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }

        return null;
    }

    private function soldAt(string $cells): ?CarbonImmutable
    {
        if (! preg_match('/<td class="date">\s*([\d-]{8,10})\s*<\/td>/', $cells, $m)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($m[1]);
        } catch (\Throwable) {
            return null;
        }
    }
}
