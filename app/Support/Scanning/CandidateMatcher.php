<?php

namespace App\Support\Scanning;

use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves an IdentifiedCard against the catalog. The precise vision-extracted
 * identifiers (number + language + name + set) do the narrowing; this returns a
 * ranked, scored shortlist of real catalog_items — never invents a card.
 * Language is a HARD filter (§8: the language is part of the identity, never
 * inferred away).
 */
class CandidateMatcher
{
    /**
     * Card-mechanic tokens that are NOT part of a card's core identity: "ex",
     * "gx", "mega", … appear across thousands of unrelated cards, so a match on
     * these alone ("Mega Greninja ex" vs "Mega Charizard ex") is meaningless. The
     * core name (the Pokémon/trainer name) must agree; these only refine the rank.
     *
     * @var array<int, string>
     */
    protected const TYPE_TOKENS = [
        'ex', 'gx', 'v', 'vmax', 'vstar', 'vunion', 'break', 'prime',
        'tag', 'team', 'lv', 'mega',
    ];

    /**
     * @return array<int, array{item: CatalogItem, score: float, reasons: array<int, string>}>
     */
    public function match(IdentifiedCard $card): array
    {
        $numerator = $this->numerator($card->number);
        $nameTokens = $this->tokens($card->name);

        if ($numerator === null && $nameTokens === []) {
            return []; // nothing to match on
        }

        // Narrow the DB fetch by the CORE name tokens (the Pokémon/trainer name),
        // not the shared mechanic suffixes — so "Mega Greninja EX" queries on
        // "greninja", not the flood of every "ex"/"mega" card.
        $coreTokens = array_values(array_diff($nameTokens, self::TYPE_TOKENS));
        $queryTokens = $coreTokens !== [] ? $coreTokens : $nameTokens;

        // Both a number (hundreds share one) and a broad name token (e.g. "vmax",
        // also hundreds) are individually weak, so an unordered window can drop
        // the real card. Order by a relevance proxy that rewards matching the
        // number AND the name together, so the exact card is always in the window.
        $relevance = [];
        $bindings = [];
        if ($numerator !== null) {
            $relevance[] = '(CASE WHEN `number` = ? THEN 2 WHEN `number` LIKE ? THEN 1 ELSE 0 END)';
            $bindings[] = $numerator;
            $bindings[] = $numerator.'/%';
        }
        if ($card->name) {
            $relevance[] = '(CASE WHEN `name` LIKE ? THEN 2 ELSE 0 END)';
            $bindings[] = '%'.$card->name.'%';
        }

        $items = CatalogItem::query()
            ->with(['set', 'productLine', 'defaultMarketValue.gradingCompany'])
            ->when($card->language, fn (Builder $q) => $q->where('language', $card->language))
            ->where(function (Builder $q) use ($numerator, $queryTokens) {
                if ($numerator !== null) {
                    $q->orWhere('number', 'like', $numerator.'/%')->orWhere('number', $numerator);
                }
                foreach ($queryTokens as $t) {
                    $q->orWhere('name', 'like', '%'.$t.'%');
                }
            })
            ->when($relevance !== [], fn (Builder $q) => $q->orderByRaw(implode(' + ', $relevance).' DESC', $bindings))
            ->orderByDesc('popularity')
            ->limit(100)
            ->get();

        return $items
            ->map(fn (CatalogItem $item) => $this->score($item, $card, $numerator, $nameTokens))
            ->filter(fn ($scored) => $scored['score'] > 0)
            ->sortByDesc('score')
            ->take((int) config('scanning.max_candidates', 5))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $nameTokens
     * @return array{item: CatalogItem, score: float, reasons: array<int, string>}
     */
    protected function score(CatalogItem $item, IdentifiedCard $card, ?string $numerator, array $nameTokens): array
    {
        $score = 0.0;
        $reasons = [];

        // 1) NAME — the anchor. It's the field the AI reads reliably, so it both
        //    gates and weights everything else. A real match needs the CORE name
        //    to agree; a shared mechanic suffix ("ex"/"mega") alone is not a match
        //    ("Greninja ex" ⇄ "Mega Greninja ex" match on "greninja"; "Mega
        //    Greninja ex" ⇄ "Mega Charizard ex" don't). nameQuality is the balanced
        //    (Dice) overlap, so an item carrying EXTRA identity tokens the read
        //    didn't ("Mega") ranks below the exact name.
        $nameHit = false;
        $nameQuality = 1.0; // when no name was read, number/code carry full weight
        if ($nameTokens !== []) {
            $itemTokens = $this->tokens($item->name);
            $hits = count(array_intersect($nameTokens, $itemTokens));
            $readCore = array_diff($nameTokens, self::TYPE_TOKENS);
            $itemCore = array_diff($itemTokens, self::TYPE_TOKENS);
            $coreHit = $readCore === []
                ? $hits > 0
                : array_intersect($readCore, $itemCore) !== [];

            if ($coreHit) {
                $nameHit = true;
                $nameQuality = 2 * $hits / (count($nameTokens) + count($itemTokens));
                $score += 0.4 * $nameQuality;
                $reasons[] = 'name';
            } else {
                // A read name that doesn't match the core name → drop outright. No
                // number/set-code coincidence rescues a wrong-name card (that's how
                // "Cinccino ex" landed on "Drowzee #16"). The scanner then asks the
                // user to search rather than show an unrelated card.
                return ['item' => $item, 'score' => 0.0, 'reasons' => $reasons];
            }
        }

        // 2) NUMBER — a strong positive, but the AI often misreads/fabricates it,
        //    so a mismatch is NOT penalised (that punishes the correctly-named
        //    card) and its credit is SCALED by name quality, so a bare number
        //    coincidence can't lift a weak partial-name match above an exact one.
        if ($numerator !== null && $item->number) {
            if ($card->number && strcasecmp($item->number, $card->number) === 0) {
                $score += 0.5 * $nameQuality;
                $reasons[] = 'number';
            } elseif ($this->numerator($item->number) === $numerator) {
                $score += 0.4 * $nameQuality;
                $reasons[] = 'number';
            }
        }

        // 3) SET CODE — "MEW" = 151, "BLK" = Black Bolt: pins the exact set where
        //    the fuzzy set NAME can't. Same treatment as the number: reward a hit
        //    (scaled by name quality), never penalise a miss.
        $codeCompared = false;
        if ($card->setCode && $item->set?->code) {
            $read = $this->normCode($card->setCode);
            $itemCode = $this->normCode($item->set->code);
            if ($read !== '' && $itemCode !== '') {
                $codeCompared = true;
                if ($read === $itemCode) {
                    $score += 0.35 * $nameQuality;
                    $reasons[] = 'set_code';
                }
            }
        }

        // Fall back to the fuzzy set NAME only when the code didn't already decide.
        if (! $codeCompared && $card->setName && $item->set && $this->setMatches($card->setName, $item->set->name)) {
            $score += 0.2 * $nameQuality;
            $reasons[] = 'set';
        }

        // 4) PRINTING — the vision-detected edition/variant breaks the tie between
        //    otherwise-identical printings (Unlimited vs 1st Edition vs Shadowless,
        //    holo vs reverse). A clear mismatch is demoted so it never wins.
        $attributes = $item->getAttribute('attributes') ?? [];

        if ($card->edition && isset($attributes['edition'])) {
            if ($card->edition === $attributes['edition']) {
                $score += 0.25;
                $reasons[] = 'edition';
            } else {
                $score -= 0.25;
            }
        }

        if ($card->variant && isset($attributes['variant'])) {
            $reverseMismatch = ($card->variant === 'reverse_holo') !== ($attributes['variant'] === 'reverse_holo');
            if ($card->variant === $attributes['variant']) {
                $score += 0.15;
                $reasons[] = 'variant';
            } elseif ($reverseMismatch) {
                $score -= 0.2; // reverse vs non-reverse is a real, value-changing mismatch
            }
        }

        return ['item' => $item, 'score' => round(min(1.0, max(0.0, $score)), 2), 'reasons' => $reasons];
    }

    /**
     * Leading number of a "029/086" style collector number, normalized so the
     * vision read and the stored number compare equal regardless of zero-padding
     * ("096" == "96"). Alphanumeric forms (promos like "SWSH004", "SV086") are
     * left intact — they're matched verbatim.
     */
    protected function numerator(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $head = trim(explode('/', $number)[0]);
        $head = preg_replace('/[^0-9A-Za-z]/', '', $head);

        if ($head === '') {
            return null;
        }

        // Purely numeric → drop leading zeros (keep a single "0").
        if (ctype_digit($head)) {
            $head = ltrim($head, '0');

            return $head === '' ? '0' : $head;
        }

        return $head;
    }

    /** Uppercased alphanumeric form of a set code, so "op07"/"OP-07" compare equal. */
    protected function normCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $code));
    }

    /**
     * Whether two set names refer to the same set. A single shared token isn't
     * enough — many sets share a common word ("Hidden Fates" vs "Paldean Fates",
     * "Crimson Haze" vs "Crimson Invasion") — so require either one name to
     * contain the other (handles "151" ⊂ "Scarlet & Violet 151") or ≥2 shared
     * tokens.
     */
    protected function setMatches(string $a, string $b): bool
    {
        $la = mb_strtolower(trim($a));
        $lb = mb_strtolower(trim($b));

        if ($la === '' || $lb === '') {
            return false;
        }

        if (mb_strlen($la) >= 3 && (str_contains($lb, $la) || str_contains($la, $lb))) {
            return true;
        }

        return count(array_intersect($this->tokens($a), $this->tokens($b))) >= 2;
    }

    /**
     * @return array<int, string>
     */
    protected function tokens(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(preg_split('/[^a-z0-9]+/i', mb_strtolower($value)))
            ->filter(fn ($t) => mb_strlen($t) >= 2)
            ->unique()
            ->values()
            ->all();
    }
}
