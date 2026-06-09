<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;

/**
 * Decides whether an eBay sold candidate is a genuine single-card sale of THIS
 * card, and resolves its priced state from the title (the §3 IdentifierStrategy
 * idea, applied to eBay text). Moderate strictness: keyword blocklist + name
 * match + single-quantity + price-sanity vs an anchor; titles also classify
 * graded (PSA/BGS/CGC/…) vs raw condition.
 */
class SoldCompClassifier
{
    /**
     * @param  array<string, int>  $companyIds  grading company slug => id
     */
    public function classify(SoldCandidate $candidate, CatalogItem $item, int $anchorCents, array $companyIds): ?SoldComp
    {
        $title = $candidate->title;
        $lower = mb_strtolower($title);

        // 1) Blocklist — mystery boxes, proxies, codes, repacks, etc.
        foreach ((array) config('valuation.ebay.blocklist', []) as $bad) {
            if (str_contains($lower, $bad)) {
                return null;
            }
        }

        // 2) Multi-quantity / lots are not single-card comps.
        if (preg_match('/\b(lot|sets?|playset|bulk|joblot)\b/', $lower)
            || preg_match('/\bx\s?\d{2,}\b/', $lower)
            || preg_match('/\b\d{2,}\s*cards?\b/', $lower)) {
            return null;
        }

        // 3) The card's primary name token must appear.
        $primary = mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) strtok($item->name, ' ')));
        if ($primary !== '' && ! str_contains((string) preg_replace('/[^a-z0-9]/', '', $lower), $primary)) {
            return null;
        }

        // 4) Price sanity vs anchor (skip when no anchor; MAD handles the rest).
        [$min, $max] = (array) config('valuation.ebay.price_band', [0.1, 5.0]);
        if ($anchorCents > 0 && ($candidate->priceCents < $anchorCents * $min || $candidate->priceCents > $anchorCents * $max)) {
            return null;
        }

        // 5) Graded? (company + grade in the title.)
        if (preg_match('/\b(psa|bgs|cgc|sgc|tag|ace)\s*(10|[1-9](?:\.5)?)\b/i', $title, $g)) {
            $slug = strtolower($g[1]);
            if (isset($companyIds[$slug])) {
                $grade = (float) $g[2];
                $label = strtoupper($slug).' '.rtrim(rtrim(sprintf('%.1f', $grade), '0'), '.');

                return new SoldComp($candidate->priceCents, $candidate->soldAt, null, $companyIds[$slug], $grade, $label, $candidate->itemId ?? '', $title, $candidate->url);
            }
        }

        // 6) Raw — infer condition (default Near Mint).
        $condition = match (true) {
            (bool) preg_match('/\b(dmg|damaged|poor)\b/', $lower) => 'DMG',
            (bool) preg_match('/\bheavily played\b|\bhp\b/', $lower) => 'HP',
            (bool) preg_match('/\bmoderately played\b|\bmp\b/', $lower) => 'MP',
            (bool) preg_match('/\b(lightly played|vlp|lp)\b/', $lower) => 'LP',
            default => 'NM',
        };

        return new SoldComp($candidate->priceCents, $candidate->soldAt, $condition, null, null, null, $candidate->itemId ?? '', $title, $candidate->url);
    }
}
