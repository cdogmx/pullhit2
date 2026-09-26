<?php

namespace App\Support\Ebay;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\Set;
use App\Support\Catalog\StampMatcher;
use App\Support\Catalog\Subsets;
use Illuminate\Database\Eloquent\Builder;

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

    /** @var array<int, array<int, string>> set id => the normalised names of its singles */
    private array $setSingleNameCache = [];

    /** @var array<int, array<int, int>> set id => that set and the subsets beside it */
    private array $setFamilyCache = [];

    public function __construct(
        private StampMatcher $stamps = new StampMatcher,
    ) {}

    /**
     * @param  array<string, int>  $companyIds  grading company slug => id
     */
    public function classify(SoldCandidate $candidate, CatalogItem $item, int $anchorCents, array $companyIds): ?SoldComp
    {
        // 0) It has to have actually sold. Every real result on a completed
        //    search carries a "Sold <date>" caption; eBay pads a thin results
        //    page with loosely related ACTIVE listings, which carry none. Those
        //    were being stored as sales dated today at their asking price — an
        //    ask is not a sale, and asks sit above the market by definition.
        if ($candidate->soldAt === null) {
            return null;
        }

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

        // A slab from a grader we do not track.
        if ($brand = $this->untrackedGraderHit($lower)) {
            return "graded by {$brand}, a company we do not track";
        }

        // Multi-quantity / lots. Note "sets" (plural) only — singular "Set" is
        // part of set names like "Base Set".
        // "8 Pokemon Pikachu … Cards" is a lot; the count needed two digits, so
        // a handful was read as one card. "Holos" plural is the same tell
        // without a number in front of it.
        // "complete set" is a lot however it is spelled; the plural "sets" was
        // the only form caught, because "Set" singular is part of set names
        // like Base Set.
        if (preg_match('/\b(lot|sets|playset|bulk|joblot|holos)\b|\b(complete|full|master)\s+set\b/', $lower)
            || preg_match('/\bx\s?\d{2,}\b/', $lower)
            || preg_match('/\b([2-9]|\d{2,})\s*cards?\b/', $lower)
            // "8 Pokemon Pikachu … Cards" — the count leads the title and the
            // noun trails it, with the whole description in between.
            || preg_match('/^\s*([2-9]|\d{2,})\s+\S.*\bcards\b/', $lower)) {
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

        // A loose single is not sold sealed. The 30th Celebration's promos ship
        // in sealed packs and their listings say so — "Pikachu ex Day MEP 107
        // Promo Holo sealed" — and two of those were priced as sales of a
        // different Pikachu entirely. Sealed products have their own gates
        // above and never reach this.
        if ($this->isSealedProduct($lower)) {
            return 'sealed product, not a loose single';
        }

        // A card of ours that this listing is more specifically about.
        if ($item->set_id && $better = $this->moreSpecificSibling($item, $lower)) {
            return "listing is for “{$better}”, not this card";
        }

        // The collector number, when the listing states one.
        if ($this->numberContradicts($item, $lower)) {
            return 'collector number does not match';
        }

        // The treatment, when the listing names one in words. A chase printing
        // often states what it is and never states its number: a "SPECIAL
        // ILLUSTRATION RARE GARDEVOIR EX" sold at $346 as a comp for the Double
        // Rare #29, which is worth about a dollar raw, because the number gate
        // had no number to judge and the name matched.
        if ($treatment = $this->treatmentContradicts($item, $lower)) {
            return "listing is a {$treatment}, this card is not";
        }

        // Printing match — keep an edition's comps from mixing with another's.
        if (! $this->printingMatches($item, $lower)) {
            return 'wrong printing / variant';
        }

        return null;
    }

    /**
     * Chase treatments a title states in words, and the rarities that may
     * legitimately carry them.
     *
     * Longest phrase first: a Special Illustration Rare title also contains
     * "illustration rare", so the specific reading has to win. Our rarity
     * vocabulary holds several spellings of one tier — "Rare Secret", "SEC" and
     * "Secret Rare" are the same thing — so each entry lists every value that
     * counts as a match rather than comparing the phrase to the stored string.
     */
    /**
     * Rarities that are plainly not a chase printing, in every spelling our
     * vocabulary holds. The treatment gate rules only on these.
     */
    private const PLAIN_RARITIES = [
        'Common', 'Uncommon', 'Rare', 'Double Rare', 'C', 'UC', 'R',
    ];

    private const TREATMENTS = [
        'special illustration rare' => ['Special Illustration Rare', 'Special Art Rare'],
        'shiny ultra rare' => ['Shiny Ultra Rare', 'Shiny Rare', 'Rare Shiny', 'Shiny Secret Rare'],
        'illustration rare' => [
            'Illustration Rare', 'Special Illustration Rare', 'Art Rare', 'Special Art Rare',
        ],
        'hyper rare' => ['Hyper Rare', 'Mega Hyper Rare', 'Rare Rainbow', 'Rare Secret', 'SEC'],
        'secret rare' => [
            'Rare Secret', 'SEC', 'Secret Rare', 'Rare Rainbow', 'Hyper Rare',
            'Shiny Secret Rare', 'Mega Hyper Rare',
        ],
    ];

    /**
     * The treatment a title claims, when this card is demonstrably not it.
     *
     * Only judges when we actually hold a rarity for the card — an unknown
     * rarity cannot contradict anything, and guessing would reject comps for
     * exactly the cards with the least data on them.
     */
    private function treatmentContradicts(CatalogItem $item, string $lower): ?string
    {
        $rarity = trim((string) $item->rarity);

        if ($rarity === '') {
            return null;
        }

        // A stated collector number outranks a stated treatment. numberContradicts
        // has already rejected the listings that state a number and get it wrong,
        // so any number still standing is this card's — and the listing is this
        // card however the seller chose to describe it.
        //
        // This matters because our own rarity is often the weaker fact. A "Chaos
        // Rising Illustration Rare" at 090/086 really is one whatever we have
        // stored, and judging the words over the number rejected 3,638 comps on
        // that single pattern alone.
        if ($this->statesAnIdentifier($lower)) {
            return null;
        }

        // Only judged for cards whose rarity could not be mistaken for a chase
        // printing. Our rarity data is the weak side of this comparison — a
        // Neo Destiny Shining Charizard is stored "Rare Shining" and really is
        // the set's secret rare, a Call of Legends SL10 is stored "Rare Holo"
        // and really is one too. Enumerating every synonym would be a losing
        // game, so the gate simply declines to judge anything that might be a
        // chase card and rules only on the tiers that plainly are not.
        if (! in_array($rarity, self::PLAIN_RARITIES, true)) {
            return null;
        }

        foreach (self::TREATMENTS as $phrase => $allowed) {
            if (! str_contains($lower, $phrase)) {
                continue;
            }

            // First phrase wins: the list runs most specific to least, so a
            // Special Illustration Rare is judged as one and not as a plain
            // Illustration Rare.
            return in_array($rarity, $allowed, true) ? null : $phrase;
        }

        return null;
    }

    /**
     * Whether a title pins itself to a specific card by number at all.
     *
     * Wider than {@see statedNumbers()} on purpose, and used only to stand the
     * treatment gate down. It also counts the hyphenated codes One Piece and its
     * starter decks use — "OP14-119", "ST15-001", "PRB02-002" — which are not
     * written as a fraction and so are invisible to the number gate. Counting
     * them here only ever makes the treatment gate fire less, which is the safe
     * direction for a title that has already told us which card it is.
     */
    private function statesAnIdentifier(string $lower): bool
    {
        return $this->statedNumbers($lower) !== []
            || preg_match('/(?:\b|#)[a-z]{1,4}[0-9]{0,2}-[0-9]{1,4}[a-z]?\b/u', $lower) === 1
            // "#SL10", "#SM155" — a hash and a set code with no hyphen.
            || preg_match('/#\s*[a-z]{1,3}[0-9]{1,4}\b/u', $lower) === 1;
    }

    /**
     * Every collector number a title states, in the two forms sellers write.
     *
     * @return array<int, string>
     */
    private function statedNumbers(string $lower): array
    {
        $stated = [];

        // "220/214", "84/147", "TG12/TG30" — numerator is the collector number,
        // but only where the denominator is a set size (see isNotASetSize).
        if (preg_match_all('#\b([0-9a-z]{1,5})\s*/\s*([0-9a-z]{1,5})\b#u', $lower, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (self::isNotASetSize($match[2])) {
                    continue;
                }

                $stated[] = $match[1];
            }
        }

        // "… Burning Shadows #84" — the other way sellers write it. The hash is
        // required for a bare number, so an HP or a year cannot be mistaken for
        // a collector number.
        if (preg_match_all('/#\s*([0-9]{1,4})\b/u', $lower, $matches)) {
            $stated = array_merge($stated, $matches[1]);
        }

        // "SWSH144", "XY183", "OP02-031" — a set code carrying its number, which
        // sellers of promos often give instead of a fraction. Invisible until
        // now, and not a quiet gap: such a title read as stating no number at
        // all, so the number gate had nothing to judge.
        //
        // The code and the number have to be ONE token — joined, or hyphenated.
        // Allowing a space between them made every word followed by a digit a
        // collector number: "SECRET RARE 1/20k" stated card 1, and six tests
        // said so immediately. It costs the spaced form ("MEP 107"), which is
        // the right trade — a gate that reads numbers that are not there is
        // worse than one that misses some that are.
        foreach (['/\b([a-z]{2,5})([0-9]{1,4})\b/u', '/\b([a-z]{2,4}[0-9]{1,2})-([0-9]{1,4})\b/u'] as $pattern) {
            if (! preg_match_all($pattern, $lower, $matches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($matches as $match) {
                if (in_array($match[1], self::NOT_A_SET_CODE, true)) {
                    continue;
                }

                // Both readings. We store "SM168" with its prefix and "149"
                // without one, and a title saying SM168 has to satisfy either.
                $stated[] = $match[1].$match[2];
                $stated[] = $match[2];
            }
        }

        return $stated;
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
    /**
     * A "N/M" that is not a card out of a set of M.
     *
     * Pull odds: "20k" is shorthand for twenty thousand, and four figures is
     * past the size of any set — the largest we hold runs to the high hundreds.
     * A seller who beats the odds says so, and "MEW B/RGB SECRET RARE 1/20k PACK
     * HIT" read as collector number 1, contradicted the card's own number, and
     * threw away a $100,000 sale.
     *
     * Colourways: that same title says "B/RGB", which is the blue print's code
     * off the card face and parsed just as happily as a collector number — so
     * the one listing that names its colour unambiguously was the one rejected
     * for naming it.
     */
    private static function isNotASetSize(string $denominator): bool
    {
        return $denominator === 'rgb'
            || (bool) preg_match('/^\d+k$/', $denominator)
            || (ctype_digit($denominator) && (int) $denominator > 999);
    }

    private function numberContradicts(CatalogItem $item, string $lower): bool
    {
        $own = $this->normalizeNumber((string) $item->number);

        if ($own === '') {
            return false;
        }

        $stated = $this->statedNumbers($lower);

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
        //
        // "graded?" is in the filler list because "PSA GRADED 10" and "PSA
        // Grade 8" are how sellers most often write it, and without it 267
        // stored comps across 237 cards — $72,626 of slab money — had been
        // filed as RAW cards. The trailing \b is load-bearing: "PSA Graded
        // 1st Edition" otherwise reads the 1 of "1st" as the grade and invents
        // a PSA 1 out of a listing that never stated one.
        if (preg_match('/\b(psa|bgs|cgc|sgc|tag|ace|beckett)[\s-]*(?:(?:gem|mint|mt|pristine|black|label|gm|graded?)[\s-]+){0,4}(10|[1-9](?:\.5)?)\b/i', $title, $g)) {
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
     * Grading companies that exist but that we hold no value model for.
     *
     * Their slabs are not raw cards, and their grades do not map onto the six
     * we do track — BCCG 10 is roughly a nice ungraded card, while AGS, GMA and
     * Arena Club are newer and grade to their own scales. Storing them under a
     * graded state would imply we can price them; storing them as raw is what
     * put a $53.35 value on a $4.28 card. So the comp is dropped.
     *
     * "mnt" is deliberately absent: sellers abbreviate MINT that way, and the
     * stored data has "GM MNT 9.5 BGS", which is a Beckett slab, not an MNT one.
     */
    private const UNTRACKED_GRADERS = [
        'bccg', 'bvg', 'gma', 'hga', 'csg', 'ksa', 'isa', 'pgc', 'rcg', 'ags', 'wcg', 'arena club',
    ];

    /**
     * The untracked grader this title names alongside a grade, or null.
     *
     * A grade number is required, so a stray three letters in a description
     * cannot drop a genuine listing — the brand has to be used the way a slab
     * is described.
     */
    private function untrackedGraderHit(string $lower): ?string
    {
        foreach (self::UNTRACKED_GRADERS as $brand) {
            if (preg_match('/\b'.preg_quote($brand, '/').'[\s-]*(?:(?:gem|mint|mt|pristine|graded?)[\s-]+){0,3}(?:10|[1-9](?:\.5)?)\b/i', $lower)) {
                return strtoupper($brand);
            }
        }

        return null;
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
    /**
     * Letter runs that precede a number without being a set code: graders, the
     * condition ladder, and the units a card's own stats are written in. "PSA
     * 10" is not card number 10.
     */
    private const NOT_A_SET_CODE = [
        'psa', 'bgs', 'cgc', 'sgc', 'ace', 'tag', 'gma', 'hga', 'csg', 'ksa', 'isa', 'pgc',
        'nm', 'lp', 'mp', 'hp', 'dmg', 'vg', 'gem', 'mint', 'ex', 'gx', 'vmax', 'vstar',
        'lot', 'x', 'no', 'vol', 'pt', 'lv', 'qty', 'pcs', 'set', 'of', 'and', 'or', 'the',
    ];

    /** How far apart two stated numbers may sit and still read as a pair. */
    private const JOIN_DISTANCE = 40;

    /**
     * How far apart two card NAMES may sit and still read as a pair.
     *
     * Much tighter than the number distance, because the join has to be the
     * only thing between them: "Surfing Pikachu V and Flying Pikachu V" is a
     * pair, while two names at opposite ends of a description are not.
     */
    private const PAIR_JOIN_DISTANCE = 14;

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

        if (self::statesTwoCardNumbers($lower)) {
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
     * Two different collector numbers, joined — "#107 & #109", "Charizard #4 &
     * Pikachu #58", "#150, #151". One listing, two cards, and the price is for
     * the pair; recorded against either card alone it roughly doubles it.
     *
     * The earlier gates cannot see these. Counting stated numbers needs three
     * before it calls a title a bundle, a pair joined by "&" is only two, and
     * the sibling-name gate needs two OTHER cards named — where a Day/Night pair
     * shares one name, it sees none.
     *
     * Three details keep it honest, each of them a false positive found by
     * running the rule over all 1.28M stored comps:
     *
     * - The numbers must differ. "#110 Numel, C, cd1 #110" states one number
     *   twice, with a comma between, and is one card.
     * - They must be joined. PSA resellers end a title with a photo marker —
     *   "#124 Mega Zygarde ex #1" — which is attached to nothing.
     * - The join must be nearby. Two numbers at opposite ends of a title with a
     *   comma somewhere between them are not a pair.
     *
     * With all three, the rule rejects 68 of 1,278,088 stored comps (0.005%),
     * and every one of them is a genuine two-card sale.
     */
    private static function statesTwoCardNumbers(string $lower): bool
    {
        $stated = [];

        // Two ways a listing states a collector number: "#107" and "105/86".
        // The slashed form was missing, and it is how most modern listings write
        // one — "Cinccino EX 105/86 & Cinccino EX 73/86" is two cards, read as
        // one, at the price of the pair.
        //
        // A BARE number is deliberately not counted. Set names carry them:
        // "Scarlet & Violet 151" puts a number beside an ampersand in an
        // entirely ordinary single-card title, and counting it would reject
        // every 151 listing we hold.
        foreach (['/#\s?(\d{1,3})(?![\d\/])/', '/(\d{1,3})\s*\/\s*\d{1,4}/'] as $pattern) {
            preg_match_all($pattern, $lower, $m, PREG_OFFSET_CAPTURE);

            foreach ($m[1] as $k => $capture) {
                $stated[] = [
                    'num' => (int) $capture[0],
                    // Span of the WHOLE match, so the gap between two numbers is
                    // not measured through the "/217" of the first one.
                    'start' => $m[0][$k][1],
                    'end' => $m[0][$k][1] + strlen($m[0][$k][0]),
                ];
            }
        }

        usort($stated, fn ($a, $b) => $a['start'] <=> $b['start']);

        for ($i = 0; $i < count($stated); $i++) {
            for ($j = $i + 1; $j < count($stated); $j++) {
                if ($stated[$i]['num'] === $stated[$j]['num']) {
                    continue;
                }

                $from = $stated[$i]['end'];
                $gap = $stated[$j]['start'] - $from;

                if ($gap < 0) {
                    continue; // overlapping matches over the same text
                }

                $between = substr($lower, $from, $gap);

                if (strlen($between) <= self::JOIN_DISTANCE
                    && preg_match('/&|\+|,|\band\b|\bvs\.?\b/', $between)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is this listing the sealed product rather than a card out of it?
     *
     * Sets name a box after their headline card — "Sylveon ex Box", the "30th
     * Celebration Tin (Sylveon or Greninja)" — and those sold at $55 against a
     * single worth about eight, eighteen of them, setting its price.
     *
     * Three conditions, and the last two are what make it safe. Measured over
     * 100,000 singles' comps it rejects 169 (0.17%), and every one is a box, a
     * tin, a blister or a booster set:
     *
     * - It names a sealed form. On its own that catches 533 and is far too
     *   blunt, because those words also describe where a CARD came from.
     * - It states no collector number anywhere. A card listing almost always
     *   gives one; a box has none to give. This only became usable once the
     *   parser learned to read "SWSH144" and "OP02-031", without which real
     *   promo singles looked numberless and were condemned.
     * - It does not name a card by its origin — a Black Star promo, a promo
     *   card, a box topper. A bare "promo" will not do: a box can be full of
     *   them, and "30th Celebration Box … Birds Promo Booster" is the box.
     */
    private function isSealedProduct(string $lower): bool
    {
        if (preg_match('/\bsealed\b/', $lower)) {
            return true;
        }

        if (! preg_match('/\b(boxes|box|tins|tin|etb|elite trainer|booster bundle|blister)\b/', $lower)) {
            return false;
        }

        // A bare "promo" is not the discriminator — a box can be full of them:
        // "30th Celebration Box … Birds Promo Booster" is the box. What names a
        // CARD by its origin is the specific wording: a Black Star promo, a
        // promo card, a box topper.
        if (preg_match('/\bblack\s+star\b|\bpromo\s+cards?\b|\bbox\s+topper\b/', $lower)) {
            return false;
        }

        return $this->statedNumbers($lower) === [];
    }

    /**
     * A card of ours that this listing is more specifically about than we are.
     *
     * "Reshiram & Charizard GX" is not a sale of Charizard; "M Charizard EX" is
     * not a sale of Charizard either, and it runs many multiples of one. Both
     * name our card, state no number, and sail through every other gate.
     *
     * The question is deliberately asked of our own catalog, never of the title:
     * does some OTHER card we hold match this title better than this one does?
     * The obvious version — "the title says a rank this card has not got" — is
     * unsafe, because our own names have gaps. We store cards as "Beedrill",
     * "Cinderace" and "Gengar" that are really Beedrill-EX, Cinderace V and
     * Gengar Prime, and there the seller is right and we are wrong; judging by
     * our name would have deleted 20,034 perfectly good sales to catch these.
     * Asking instead whether we hold something more specific cannot make that
     * mistake: where the more specific card is missing, nothing fires.
     *
     * Rejects 2,486 of 1,244,542 stored comps (0.2%).
     */
    private function moreSpecificSibling(CatalogItem $item, string $lower): ?string
    {
        $own = $this->nameCore($item->name);

        if (mb_strlen($own) < 4) {
            return null;
        }

        $haystack = self::flatten($lower);

        foreach ($this->siblingSingleCores($item) as $core) {
            // Strictly more specific: it has to contain our whole name and say
            // something further. A shorter or equal name is not a better match.
            if (mb_strlen($core) <= mb_strlen($own) || ! str_contains(' '.$core.' ', ' '.$own.' ')) {
                continue;
            }

            if (str_contains($haystack, ' '.$core.' ')) {
                return $core;
            }
        }

        return null;
    }

    /**
     * The normalised names of the SINGLES in this card's set, cached per set.
     *
     * Singles only, because a sealed product is not a more specific card. Its
     * set ships a deck named after its own headline card — "Starter Deck 23: Red
     * Shanks" — and the singles out of that deck name the deck in their titles,
     * so counting the sealed row made every Shanks single read as a sale of the
     * box it came in.
     *
     * @return array<int, string>
     */
    private function siblingSingleCores(CatalogItem $item): array
    {
        return $this->setSingleNameCache[$item->set_id] ??= CatalogItem::query()
            ->whereIn('set_id', $this->family($item))
            ->where('item_type', ItemType::Single)
            ->pluck('name')
            ->map(fn ($n) => $this->nameCore((string) $n))
            ->filter(fn ($n) => mb_strlen($n) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * This card's set and the subsets alongside it.
     *
     * A set's Classic Collection and its promo run are separate sets that share
     * a numbering space with it, and one set's worth of siblings cannot see
     * across that gap. The 30th Celebration has a Pikachu at 33 and a Pikachu &
     * Zekrom GX at 33 in its Classic Collection, so twelve sales of the tag team
     * were recorded as sales of the Pikachu: the numerator matched, and the card
     * that would have explained the title was one set over.
     *
     * The home page, the intraday readings and the price race already treat
     * these three as one thing. So does this now.
     *
     * @return array<int, int>
     */
    private function family(CatalogItem $item): array
    {
        return $this->setFamilyCache[$item->set_id] ??= (function () use ($item) {
            $set = $item->set;

            if (! $set) {
                return [$item->set_id];
            }

            return Set::query()
                ->where('product_line_id', $set->product_line_id)
                ->where('language', $set->language)
                ->where(fn (Builder $q) => $q
                    ->whereKey($set->getKey())
                    // The parent, if this card is IN a subset …
                    ->orWhere('name', $this->parentName($set->name))
                    // … or the subsets, if this card is in the parent.
                    ->orWhereIn('name', array_map(
                        fn (string $suffix) => $set->name.' '.$suffix,
                        Subsets::SUFFIXES,
                    )))
                ->pluck('id')
                ->all();
        })();
    }

    /** "30th Celebration Promos" → "30th Celebration"; anything else → itself. */
    private function parentName(string $name): string
    {
        return Subsets::split($name)[0] ?? $name;
    }

    /**
     * The normalised names of every card in this card's set, cached per set.
     *
     * @return array<int, string>
     */
    private function siblingCores(CatalogItem $item): array
    {
        return $this->setNameCache[$item->set_id] ??= CatalogItem::query()
            ->where('set_id', $item->set_id)
            ->pluck('name')
            ->map(fn ($n) => $this->nameCore((string) $n))
            ->filter(fn ($n) => mb_strlen($n) >= 4)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A title reduced to space-separated words, padded so phrases match whole.
     *
     * "and" is dropped because it is the word form of "&", which punctuation
     * stripping already removes: we hold the card as "Pikachu & Zekrom-GX", so a
     * seller writing "Pikachu And Zekrom GX" would otherwise not match the name
     * of the very card they are selling.
     */
    private static function flatten(string $lower): string
    {
        $words = (string) preg_replace('/[^a-z0-9]+/', ' ', $lower);
        $words = (string) preg_replace('/\band\b/', ' ', $words);

        // Collapse afterwards, or the gap the joiner left keeps the two names
        // apart: "pikachu and zekrom" became "pikachu   zekrom", and the phrase
        // being hunted has one space in it.
        return ' '.trim((string) preg_replace('/\s+/', ' ', $words)).' ';
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
        $siblings = $this->siblingCores($item);

        $haystack = self::flatten($lower);

        $others = 0;
        foreach ($siblings as $core) {
            if (str_contains($ownPhrase, ' '.$core.' ')) {
                continue;   // our own name, whole or in part — not another card
            }

            if (str_contains($haystack, ' '.$core.' ') && ++$others >= 2) {
                return true;
            }
        }

        return $this->namesOnePairedSetCard($lower, $own, $siblings, $item);
    }

    /**
     * Our card and exactly ONE other from its set, joined — "Surfing Pikachu V
     * and Flying Pikachu V". Two cards, one price, and recorded against either
     * one it prices a pair as a single.
     *
     * The gate above needs two OTHER cards named, so a plain pair slips past it,
     * and neither number rule can help: a title like this states no collector
     * number at all.
     *
     * Kept narrow on purpose, because a loose version of this is worse than the
     * bug. The join has to sit BETWEEN our name and the other card's — an
     * ampersand elsewhere in the title is usually a set name ("Scarlet & Violet")
     * or a shipping note ("NM & SHIPS FAST") — it has to be close, and a sibling
     * whose name is part of the SET's name does not count, or every "151 MEW"
     * listing would read as a Mew bundle.
     *
     * @param  array<int, string>  $siblings
     */
    private function namesOnePairedSetCard(string $lower, string $own, array $siblings, CatalogItem $item): bool
    {
        // NOT flatten(): that strips the word "and", which is the very thing
        // this rule is looking for. Punctuation becomes space, nothing else.
        $haystack = ' '.trim((string) preg_replace('/\s+/', ' ',
            (string) preg_replace('/[^a-z0-9&+]+/', ' ', $lower))).' ';

        if ($own === '' || ($ours = strpos($haystack, ' '.$own.' ')) === false) {
            return false;
        }

        $setName = self::flatten(mb_strtolower((string) $item->set?->name));
        $ownStart = $ours + 1;
        $ownEnd = $ownStart + strlen($own);

        foreach ($siblings as $core) {
            if ($core === '' || str_contains(' '.$own.' ', ' '.$core.' ')) {
                continue;
            }

            // A card whose name the set also carries tells us nothing.
            if ($setName !== '' && str_contains(' '.$setName.' ', ' '.$core.' ')) {
                continue;
            }

            $at = strpos($haystack, ' '.$core.' ');

            if ($at === false) {
                continue;
            }

            $start = $at + 1;
            $end = $start + strlen($core);

            // Whichever comes first, look only at the text between the two.
            $between = $start >= $ownEnd
                ? substr($haystack, $ownEnd, $start - $ownEnd)
                : substr($haystack, $end, $ownStart - $end);

            if (strlen($between) > self::PAIR_JOIN_DISTANCE) {
                continue;
            }

            // The name cores have their suffix words stripped ("Flying Pikachu
            // V" -> "flying pikachu"), so a stray "v" is left sitting in the
            // gap. Drop those and the join has to be all that remains.
            $gap = (string) preg_replace('/\b(ex|gx|v|vmax|vstar|vunion|prime|break)\b/', ' ', $between);
            $gap = trim((string) preg_replace('/\s+/', ' ', $gap));

            if (in_array($gap, ['&', '+', 'and', 'plus', 'with'], true)) {
                return true;
            }
        }

        return false;
    }

    /** The RGB colourways, as the finish tag spells them. */
    private const COLOURWAYS = ['red', 'blue', 'green'];

    /** This card's colourway, or null when it is not one of the RGB prints. */
    private static function colourway(array $attributes): ?string
    {
        $finish = (string) ($attributes['finish'] ?? '');

        if (! str_ends_with($finish, '_rgb')) {
            return null;
        }

        $colour = substr($finish, 0, -4);

        return in_array($colour, self::COLOURWAYS, true) ? $colour : null;
    }

    /**
     * Does the title say this colour? Either as the word, or as the code printed
     * on the card face — "B/RGB" for blue — which is what the sellers who know
     * what they are holding tend to write.
     */
    private static function statesColour(string $lower, string $colour): bool
    {
        return (bool) preg_match(
            '/\b'.$colour.'\b|\b'.$colour[0].'\s*\/\s*rgb\b/',
            $lower,
        );
    }

    /** Normalised core of a card name: lowercased, suffixes (ex/gx/v/…) dropped. */

    /** Normalised core of a card name: lowercased, suffixes (ex/gx/v/…) dropped. */
    private function nameCore(string $name): string
    {
        $s = mb_strtolower($name);
        // A bracket on a card name is our disambiguator, not part of what the
        // card is called: "Articuno (30th Celebration)" kept its bracket's WORDS
        // here, so the sibling gate hunted a title for the phrase "articuno 30th
        // celebration" and never found it. A three-card listing naming Moltres,
        // Articuno and Zapdos was therefore read as a single-card sale — three
        // times over, once per card.
        $s = (string) preg_replace('/[\(\[][^\)\]]*[\)\]]/', ' ', $s);
        $s = (string) preg_replace('/\band\b/', ' ', $s);
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

        // A colourway is the whole card. The 30th Celebration's Mew exists in
        // red, blue and green; one of them sold for $100,000 and the others did
        // not, so pooling their sales would be the most expensive kind of wrong.
        //
        // It is gated rather than searched because no colour token is common to
        // the listings. One real title says "MEW B/RGB SECRET RARE", another is
        // found by "ultra rare blue mew" — and eBay ANDs, so a query naming
        // either spelling loses the other. Broad search, strict gate.
        if ($colour = self::colourway($attributes)) {
            if (! self::statesColour($lower, $colour)) {
                return false;
            }

            foreach (self::COLOURWAYS as $other) {
                if ($other !== $colour && self::statesColour($lower, $other)) {
                    return false;
                }
            }
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
