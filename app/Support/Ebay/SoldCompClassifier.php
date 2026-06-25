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
    /** @var array<int, array<int, string>> set_id => other card name cores (request cache) */
    private array $setNameCache = [];

    /**
     * @param  array<string, int>  $companyIds  grading company slug => id
     */
    public function classify(SoldCandidate $candidate, CatalogItem $item, int $anchorCents, array $companyIds): ?SoldComp
    {
        // 1-3) Structural gates: blocklist, multi-quantity, multi-card bundles,
        //      name presence, printing match. Shared with the comp-pruning pass.
        if ($this->structurallyInvalid($candidate, $item)) {
            return null;
        }

        // 4) Resolve the priced state first (graded vs raw) so the price band can
        //    judge RAW comps only — a graded sale legitimately runs many multiples
        //    of the raw anchor, and the engine's MAD pass guards within-grade.
        $comp = $this->pricedState($candidate, $companyIds);

        // 5) Price sanity vs the raw NM anchor — raw comps only (skip when there's
        //    no anchor). Graded premiums are expected, so graded comps bypass it.
        if ($comp->gradingCompanyId === null) {
            [$min, $max] = (array) config('valuation.ebay.price_band', [0.1, 5.0]);
            if ($anchorCents > 0 && ($candidate->priceCents < $anchorCents * $min || $candidate->priceCents > $anchorCents * $max)) {
                return null;
            }
        }

        return $comp;
    }

    /**
     * The non-price reject gates: a listing fails when it's blocklisted, a
     * multi-quantity lot, a multi-card bundle (by numbers OR by naming several
     * cards from the same set), doesn't name this card, or is the wrong printing.
     * Exposed so the comp-pruning pass can re-judge already-stored sales with the
     * exact same rules the live classifier uses.
     */
    public function structurallyInvalid(SoldCandidate $candidate, CatalogItem $item): bool
    {
        $lower = mb_strtolower($candidate->title);

        // Blocklist — mystery boxes, proxies, codes, repacks, etc.
        foreach ((array) config('valuation.ebay.blocklist', []) as $bad) {
            if (str_contains($lower, $bad)) {
                return true;
            }
        }

        // Multi-quantity / lots. Note "sets" (plural) only — singular "Set" is
        // part of set names like "Base Set".
        if (preg_match('/\b(lot|sets|playset|bulk|joblot)\b/', $lower)
            || preg_match('/\bx\s?\d{2,}\b/', $lower)
            || preg_match('/\b\d{2,}\s*cards?\b/', $lower)) {
            return true;
        }

        // Multi-card bundles (e.g. First Partners starter sets).
        if ($this->isMultiCardTitle($lower, $item)) {
            return true;
        }

        // The card's primary name token must appear.
        $primary = mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) strtok($item->name, ' ')));
        if ($primary !== '' && ! str_contains((string) preg_replace('/[^a-z0-9]/', '', $lower), $primary)) {
            return true;
        }

        // Printing match — keep an edition's comps from mixing with another's.
        return ! $this->printingMatches($item, $lower);
    }

    /**
     * Resolve a candidate's priced state from its title — graded (company +
     * grade) or raw (inferred condition, default Near Mint) — without the
     * accept/reject gates. Used by classify() after its gates, and directly by
     * an admin reassign where the card is asserted by hand.
     *
     * @param  array<string, int>  $companyIds  grading company slug => id
     */
    public function pricedState(SoldCandidate $candidate, array $companyIds): SoldComp
    {
        $title = $candidate->title;
        $lower = mb_strtolower($title);

        // Graded: "PSA 10", "BGS 9.5", "Beckett 10", "PSA-10", and the
        // company/number-separated-by-grade-words form "PSA GEM MINT 10".
        if (preg_match('/\b(psa|bgs|cgc|sgc|tag|ace|beckett)[\s-]*(?:(?:gem|mint|mt|pristine|black|label|gm)\s+){0,4}(10|[1-9](?:\.5)?)\b/i', $title, $g)) {
            $slug = strtolower($g[1]);
            $slug = $slug === 'beckett' ? 'bgs' : $slug;
            if (isset($companyIds[$slug])) {
                $grade = (float) $g[2];
                $label = strtoupper($slug).' '.rtrim(rtrim(sprintf('%.1f', $grade), '0'), '.');

                return new SoldComp($candidate->priceCents, $candidate->soldAt, null, $companyIds[$slug], $grade, $label, $candidate->itemId ?? '', $title, $candidate->url, $candidate->seller, $candidate->imageUrl);
            }
        }

        // Pokémon cards print an HP stat ("320 HP") — strip it so it isn't read as
        // the "HP" (Heavily Played) condition abbreviation.
        $cond = (string) preg_replace('/\b\d{1,3}\s*hp\b|\bhp\s*\d{1,3}\b/', ' ', $lower);

        $condition = match (true) {
            (bool) preg_match('/\b(dmg|damaged|poor)\b/', $cond) => 'DMG',
            (bool) preg_match('/\bheavily played\b|\bhp\b/', $cond) => 'HP',
            (bool) preg_match('/\bmoderately played\b|\bmp\b/', $cond) => 'MP',
            (bool) preg_match('/\b(lightly played|vlp|lp)\b/', $cond) => 'LP',
            default => 'NM',
        };

        return new SoldComp($candidate->priceCents, $candidate->soldAt, $condition, null, null, null, $candidate->itemId ?? '', $title, $candidate->url, $candidate->seller, $candidate->imageUrl);
    }

    /**
     * Does the title describe several different cards (a multi-card bundle/set),
     * so it isn't a single-card comp? Tells, all robust to graded titles:
     *  - explicit set language ("set of 3", "starter set", "starter pack"), or
     *  - a "+"-joined bundle ("038 + Squirtle 039", "Charizard + Pikachu"), or
     *  - 3+ distinct collector numbers once set totals/years/grades/HP/levels
     *    are stripped ("37 38 39"), or
     *  - it names 2+ OTHER cards from this card's own set (e.g. a First Partners
     *    listing that lists "Chikorita Cyndaquil Totodile" — only one is ours).
     */
    private function isMultiCardTitle(string $lower, ?CatalogItem $item = null): bool
    {
        // Explicit multi-card language. "set of N", a "starter/promo/gift set",
        // or "starters" (plural) never describe a single card.
        if (preg_match('/\bset of \d+\b|\b(starter|promo|gift|collection)\s+(set|pack|box)\b|\bstarters\b/', $lower)) {
            return true;
        }

        if (preg_match('/\d\s*\+\s*[a-z]|[a-z]\s*\+\s*\d/', $lower)) {
            return true;
        }

        $t = (string) preg_replace('#(\d{1,4})\s*/\s*\d{1,4}#', ' $1 ', $lower);   // N/M -> N
        $t = (string) preg_replace('/\b(?:19|20)\d{2}\b/', ' ', $t);               // years
        $t = (string) preg_replace('/\b(?:psa|bgs|cgc|sgc|tag|ace|beckett)\s*\d+(?:\.\d)?\b/', ' ', $t); // grades
        $t = (string) preg_replace('/\b\d{1,3}\s*hp\b/', ' ', $t);                 // HP
        $t = (string) preg_replace('/\b(?:lv|level)\.?\s*\d+\b/', ' ', $t);        // levels

        preg_match_all('/(?<![\w.])\d{1,3}(?![\w.\/])/', $t, $m);
        $distinct = array_unique(array_filter(array_map(
            fn ($n) => (int) ltrim($n, '0'),
            $m[0],
        ), fn ($n) => $n >= 1));

        if (count($distinct) >= 3) {
            return true;
        }

        return $item !== null && $this->namesOtherSetCards($lower, $item);
    }

    /**
     * Whether the title names 2+ OTHER cards from this card's set — the tell for a
     * starter/partner set that lists every character (only one of which is ours).
     * Matches each sibling's full core name as a whole phrase, so shared-prefix
     * names ("Iron Hands" vs "Iron Valiant") don't collide.
     */
    private function namesOtherSetCards(string $lower, CatalogItem $item): bool
    {
        if (! $item->set_id) {
            return false;
        }

        $own = $this->nameCore($item->name);
        $siblings = $this->setNameCache[$item->set_id] ??= CatalogItem::query()
            ->where('set_id', $item->set_id)
            ->pluck('name')
            ->map(fn ($n) => $this->nameCore((string) $n))
            ->filter(fn ($n) => mb_strlen($n) >= 4)
            ->unique()
            ->values()
            ->all();

        $haystack = ' '.trim((string) preg_replace('/[^a-z0-9]+/', ' ', $lower)).' ';

        $others = 0;
        foreach ($siblings as $core) {
            if ($core !== $own && str_contains($haystack, ' '.$core.' ') && ++$others >= 2) {
                return true;
            }
        }

        return false;
    }

    /** Normalised core of a card name: lowercased, suffixes (ex/gx/v/…) dropped. */
    private function nameCore(string $name): string
    {
        $s = mb_strtolower($name);
        $s = (string) preg_replace('/\b(ex|gx|v|vmax|vstar|v-union|vunion|prime|break|lv|tag team)\b/', ' ', $s);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    /**
     * Does this listing's title match the card's printing? An Unlimited card
     * rejects 1st-Edition/Shadowless listings; a 1st-Edition card requires the
     * stamp; a base (non-reverse) card rejects reverse-holo listings, etc.
     */
    private function printingMatches(CatalogItem $item, string $lower): bool
    {
        $attributes = $item->getAttribute('attributes') ?? [];

        $is1st = (bool) preg_match('/\b(1st|first)\s*ed(ition)?\b/', $lower);
        $isShadowless = str_contains($lower, 'shadowless');
        $isReverse = (bool) preg_match('/\breverse\b/', $lower);

        $edition = $attributes['edition'] ?? null;
        if ($edition === 'first_edition' && ! $is1st) {
            return false;
        }
        if ($edition === 'shadowless' && ! $isShadowless) {
            return false;
        }
        if ($edition === 'unlimited' && ($is1st || $isShadowless)) {
            return false;
        }

        $variant = $attributes['variant'] ?? null;
        if ($variant === 'reverse_holo' && ! $isReverse) {
            return false;
        }
        if (in_array($variant, ['normal', 'holo'], true) && $isReverse) {
            return false;
        }

        return true;
    }
}
