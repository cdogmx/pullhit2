<?php

namespace App\Support\Ebay;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Catalog\StampMatcher;

/**
 * Decides whether an eBay sold candidate is a genuine single-card sale of THIS
 * card, and resolves its priced state from the title (the §3 IdentifierStrategy
 * idea, applied to eBay text). Moderate strictness: keyword blocklist + name
 * match + single-quantity + price-sanity vs an anchor; titles also classify
 * graded (PSA/BGS/CGC/…) vs raw condition, and retailer/prerelease stamp promos.
 */
class SoldCompClassifier
{
    /** @var array<int, array<int, string>> set_id => other card name cores (request cache) */
    private array $setNameCache = [];

    public function __construct(
        private StampMatcher $stamps = new StampMatcher,
    ) {}

    /**
     * @param  array<string, int>  $companyIds  grading company slug => id
     */
    public function classify(SoldCandidate $candidate, CatalogItem $item, int $anchorCents, array $companyIds): ?SoldComp
    {
        // 1-3) Structural gates: blocklist, multi-quantity, multi-card/sealed
        //      bundles, name/variant match, printing. Shared with the prune pass.
        if ($this->structurallyInvalid($candidate, $item)) {
            return null;
        }

        // Sealed products have no graded/raw axis — a passing listing is the
        // sealed comp (condition SEALED, matching the synthetic seed's bucket),
        // subject only to the price band.
        if ($item->item_type === ItemType::Sealed) {
            return $this->bandOk($candidate->priceCents, $anchorCents)
                ? new SoldComp($candidate->priceCents, $candidate->soldAt, 'SEALED', null, null, null, $candidate->itemId ?? '', $candidate->title, $candidate->url, $candidate->seller, $candidate->imageUrl)
                : null;
        }

        // 4) Resolve the priced state first (graded vs raw) so the price band can
        //    judge RAW comps only — a graded sale legitimately runs many multiples
        //    of the raw anchor, and the engine's MAD pass guards within-grade.
        $comp = $this->pricedState($candidate, $companyIds);

        // 5) Price sanity vs the raw NM anchor — raw comps only (skip when there's
        //    no anchor). Graded premiums are expected, so graded comps bypass it.
        if ($comp->gradingCompanyId === null && ! $this->bandOk($candidate->priceCents, $anchorCents)) {
            return null;
        }

        return $comp;
    }

    /**
     * Explain how a candidate would be handled — the admin comp-preview window.
     * Returns the accept/reject verdict, the priced state it would land in on
     * accept, and the exact reject reason otherwise. Mirrors classify() gate for
     * gate (same order, same rules) so the preview shows exactly what a real
     * refresh would do — no side effects.
     *
     * @param  array<string, int>  $companyIds  grading company slug => id
     * @return array{verdict: 'ingest'|'reject', reason: ?string, state: ?string}
     */
    public function diagnose(SoldCandidate $candidate, CatalogItem $item, int $anchorCents, array $companyIds): array
    {
        if ($reason = $this->structuralRejectReason($candidate, $item)) {
            return ['verdict' => 'reject', 'reason' => $reason, 'state' => null];
        }

        if ($item->item_type === ItemType::Sealed) {
            return $this->bandOk($candidate->priceCents, $anchorCents)
                ? ['verdict' => 'ingest', 'reason' => null, 'state' => 'SEALED']
                : ['verdict' => 'reject', 'reason' => 'price outside sanity band', 'state' => 'SEALED'];
        }

        $comp = $this->pricedState($candidate, $companyIds);
        $state = $comp->gradeLabel ?? $comp->condition;

        if ($comp->gradingCompanyId === null && ! $this->bandOk($candidate->priceCents, $anchorCents)) {
            return ['verdict' => 'reject', 'reason' => 'price outside sanity band', 'state' => $state];
        }

        return ['verdict' => 'ingest', 'reason' => null, 'state' => $state];
    }

    /**
     * The blocklisted term this title trips on, or null. Two rules keep genuine
     * cards out of the junk bucket:
     *
     *  1) Whole words only. A bare str_contains rejected "BREAKthrough" on
     *     "break" and "CheSPIN" on "spin" — 69 of a 2,000-miss sample.
     *  2) A term that is part of THIS card's own identity isn't a junk signal:
     *     the card "Mystery Garden", the set "I Choose You", a "Tech Sticker"
     *     printing. Those are the product, not a bulk-lot tell.
     */
    private function blocklistHit(string $lower, CatalogItem $item): ?string
    {
        $identity = mb_strtolower($item->name.' '.($item->set?->name ?? ''));

        foreach ((array) config('valuation.ebay.blocklist', []) as $bad) {
            $term = trim((string) $bad);
            if ($term === '') {
                continue;
            }

            $pattern = '/\b'.preg_quote($term, '/').'\b/u';

            if (preg_match($pattern, $lower) && ! preg_match($pattern, $identity)) {
                return $bad;
            }
        }

        return null;
    }

    /** Price within [min, max] × anchor — true when there's no anchor to judge. */
    private function bandOk(int $priceCents, int $anchorCents): bool
    {
        if ($anchorCents <= 0) {
            return true;
        }

        [$min, $max] = (array) config('valuation.ebay.price_band', [0.1, 5.0]);

        return $priceCents >= $anchorCents * $min && $priceCents <= $anchorCents * $max;
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
        return $this->structuralRejectReason($candidate, $item) !== null;
    }

    /**
     * The specific reason a listing fails the non-price gates, or null when it
     * passes them all — the reason-returning core of {@see structurallyInvalid()}.
     * The admin comp-preview shows these verbatim; ingestion just checks for null.
     */
    public function structuralRejectReason(SoldCandidate $candidate, CatalogItem $item): ?string
    {
        return $this->titleRejectReason($item, $candidate->title);
    }

    /**
     * The same gates, judged from a title alone — so the card page's live
     * listings can hold active asks to the standard sold comps are held to.
     * Without this the panel filtered on language only, and a "YOU PICK" bulk
     * lot or a 3-card starter set was shown as an ask for one specific card.
     */
    public function titleRejectReason(CatalogItem $item, string $title): ?string
    {
        $lower = mb_strtolower($title);

        // Sealed products use their own variant-aware gates, shared with the
        // card-page listings and the for-sale ask ingest.
        if ($item->item_type === ItemType::Sealed) {
            return SealedSearch::rejectReason($item, $title);
        }

        // Blocklist — mystery boxes, proxies, codes, repacks, etc.
        if ($bad = $this->blocklistHit($lower, $item)) {
            return "blocklisted term “{$bad}”";
        }

        // Multi-quantity / lots. Note "sets" (plural) only — singular "Set" is
        // part of set names like "Base Set".
        if (preg_match('/\b(lot|sets|playset|bulk|joblot)\b/', $lower)
            || preg_match('/\bx\s?\d{2,}\b/', $lower)
            || preg_match('/\b\d{2,}\s*cards?\b/', $lower)) {
            return 'multi-quantity lot';
        }

        // Multi-card bundles (e.g. First Partners starter sets).
        if ($this->isMultiCardTitle($lower, $item)) {
            return 'multiple cards (bundle/set)';
        }

        // The card's primary name token must appear.
        $primary = mb_strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) strtok($item->name, ' ')));
        if ($primary !== '' && ! str_contains((string) preg_replace('/[^a-z0-9]/', '', $lower), $primary)) {
            return 'title does not name this card';
        }

        // The collector number, when the listing states one.
        if ($this->numberContradicts($item, $lower)) {
            return 'collector number does not match';
        }

        // Printing match — keep an edition's comps from mixing with another's.
        if (! $this->printingMatches($item, $lower)) {
            return 'wrong printing / variant';
        }

        return null;
    }

    /**
     * Whether the listing states a collector number, and none of them is this
     * card's.
     *
     * The name gate only requires the card's FIRST word, which is far too loose
     * for any card whose name starts with a shared word. "Muk & Alolan Muk-GX"
     * #220 reduces to "muk", so every solo "Alolan Muk GX" from Burning Shadows
     * — a different card, in a different set, worth a few dollars — matched, and
     * 103 of its 105 raw comps belonged to that card. The value read $5.34 where
     * its two genuine sales said about $30.
     *
     * Only the printed "N/M" form counts. A bare number is not safe to read:
     * one of those very listings ends "Full Art Holo 220HP", which a loose match
     * would have taken as agreeing with #220.
     *
     * Silence is not disagreement — plenty of honest listings never print the
     * number, so this rejects only a stated number that contradicts.
     */
    private function numberContradicts(CatalogItem $item, string $lower): bool
    {
        $own = $this->normalizeNumber((string) $item->number);

        if ($own === '') {
            return false;
        }

        $stated = [];

        // "220/214", "84/147", "TG12/TG30" — numerator is the collector number.
        if (preg_match_all('#\b([0-9a-z]{1,5})\s*/\s*[0-9a-z]{1,5}\b#u', $lower, $matches)) {
            $stated = $matches[1];
        }

        // "… Burning Shadows #84" — the other way sellers write it. Requires the
        // hash, so an HP or a year can't be mistaken for a collector number.
        if (preg_match_all('/#\s*([0-9]{1,4})\b/u', $lower, $matches)) {
            $stated = array_merge($stated, $matches[1]);
        }

        if ($stated === []) {
            return false;
        }

        foreach ($stated as $number) {
            if ($this->normalizeNumber($number) === $own) {
                return false;
            }
        }

        return true;
    }

    /**
     * Collector numbers compare zero-padding- and case-insensitively.
     *
     * A stored number may be either the bare collector number ("220") or the
     * full printed form ("276/217") depending on the importer that wrote it, and
     * titles print both. Compare numerators so the two forms agree.
     */
    private function normalizeNumber(string $number): string
    {
        $clean = mb_strtolower(trim(explode('/', $number)[0]));
        $clean = (string) preg_replace('/[^a-z0-9]/', '', $clean);

        // "007" and "7" are the same card; "tg12" keeps its prefix.
        return ltrim($clean, '0') ?: ($clean === '' ? '' : '0');
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
     *
     * A sibling whose name is part of THIS card's own name is not a second card —
     * it's our own identity (the blocklist's rule 2, applied here). "Pikachu &
     * Zekrom-GX" shares a set with a solo "Pikachu" and a solo "Zekrom-GX", so
     * every honest listing for the Tag Team card named two "other" cards and was
     * rejected as a bundle. Same for every duo/trio single (464 of them).
     */
    private function namesOtherSetCards(string $lower, CatalogItem $item): bool
    {
        if (! $item->set_id) {
            return false;
        }

        $own = $this->nameCore($item->name);
        $ownPhrase = ' '.$own.' ';
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
            if (str_contains($ownPhrase, ' '.$core.' ')) {
                continue;   // our own name, whole or in part — not another card
            }

            if (str_contains($haystack, ' '.$core.' ') && ++$others >= 2) {
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

        // Lorcana foil ("cold foil") is a distinct printing that trades well above
        // the base card — and the eBay search mixes the two. A non-foil card
        // rejects foil listings and a foil card requires the foil wording. Gated
        // to Lorcana so Pokémon "holo/foil" phrasing isn't affected.
        if ($item->productLine?->slug === 'lorcana') {
            $listFoil = (bool) preg_match('/\bfoil\b/', $lower)
                && ! (bool) preg_match('/non[\s-]?foil/', $lower);
            $itemFoil = in_array($variant, ['foil', 'cold_foil'], true);
            if ($itemFoil !== $listFoil) {
                return false;
            }
        }

        // One Piece special printings (alt-art/parallel, full-art, manga,
        // championship/winner, judge, pre-release, …) trade well above the base
        // card, and the base card's search carries no qualifier — so those sales
        // would leak in. Keep them in their own lane: a base card rejects any
        // special printing, a special card rejects plain base sales, and alt-art
        // is separated from the other specials. Gated to One Piece.
        if ($item->productLine?->slug === 'one-piece') {
            $listSpecial = (bool) preg_match('/\balt(ernate)?[\s-]*art\b|\bparallel\b|\bfull[\s-]*art\b|\bmanga\b|championship|\bwinner\b|\bfinalist\b|\btop\s*player\b|\bjudge\b|pre[\s-]?release|\berrata\b|\banniversary\b/', $lower);
            $listAlt = (bool) preg_match('/\balt(ernate)?[\s-]*art\b|\bparallel\b/', $lower);
            $finish = (string) ($attributes['finish'] ?? '');
            $itemSpecial = $finish !== '' && $finish !== 'normal';
            $itemAlt = str_contains($finish, 'alternate_art');

            if ($itemSpecial !== $listSpecial) {
                return false;
            }
            if ($itemSpecial && $itemAlt !== $listAlt) {
                return false;
            }
        }

        // Retailer / prerelease STAMP promos (GameStop, EB Games, prerelease, …)
        // are distinct printings that trade on their own. Route by the specific
        // stamp: a base card rejects any stamped listing, a stamped card requires
        // its own stamp, and one stamp never absorbs another's sales.
        return $this->stamps->matches($this->stamps->itemStamp($item), $lower);
    }
}
