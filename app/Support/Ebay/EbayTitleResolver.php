<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves a free-text eBay sold-listing title back to a catalog card — the
 * inverse of the per-card search. The collector number does the narrowing (a
 * "238/191" in the title is the strong signal); then we score by how much of a
 * candidate card's identity (name tokens, set) actually appears in the title.
 * Conservative: returns no match when nothing scores well or two cards tie, so
 * the sweep never guesses a card onto a sale.
 */
class EbayTitleResolver
{
    /**
     * Prefixes that look exactly like a One Piece card number ("SWSH09") but
     * name an expansion, not a card. Reading those as numbers produced every
     * single `unmatched` miss; set corroboration handles them instead.
     */
    private const SET_CODE_PREFIXES = ['SWSH', 'SV', 'SM', 'XY', 'BW', 'DP', 'HGSS', 'ME'];

    /** @var array<string, ?int> product-line slug => id, memoized per process. */
    private static array $lineIds = [];

    /**
     * `variants` carries every candidate sharing the winner's base_key, best
     * first — the same card's other printings. The resolver scores on name,
     * number and set, which can't tell a reverse holo from a regular one; the
     * classifier can, and rejects the whole sale when handed the wrong one. So
     * it receives the shortlist and picks the printing the title describes.
     *
     * @param  ?string  $productLine  product-line slug to confine candidates to
     *                                (e.g. 'one-piece'). A One Piece sweep was
     *                                resolving "Kozuki Momonosuke … #064" onto a
     *                                Pokémon card, because sharing a collector
     *                                number is enough when nothing scopes the game.
     * @return array{item: ?CatalogItem, score: float, number: ?string, reason: string, best_id: ?int, variants: array<int, CatalogItem>}
     */
    public function resolve(string $title, ?string $language, float $minScore, ?string $productLine = null): array
    {
        $number = $this->extractNumber($title);

        if ($number === null) {
            return ['item' => null, 'score' => 0.0, 'number' => null, 'reason' => 'no_number', 'best_id' => null, 'variants' => []];
        }

        $numerator = $this->numerator($number);
        $haystack = $this->normalize($title);

        $lineId = $this->productLineId($productLine);

        $candidates = CatalogItem::query()
            ->with(['set:id,name,code'])
            ->when($language, fn (Builder $q) => $q->where('language', $language))
            ->when($lineId, fn (Builder $q) => $q->where('product_line_id', $lineId))
            ->where(function (Builder $q) use ($number, $numerator) {
                $q->where('number', $number);
                if ($numerator !== null) {
                    $q->orWhere('number', $numerator)->orWhere('number', 'like', $numerator.'/%');
                }
            })
            ->orderByDesc('popularity')
            ->limit(80)
            ->get();

        if ($candidates->isEmpty()) {
            return ['item' => null, 'score' => 0.0, 'number' => $number, 'reason' => 'unmatched', 'best_id' => null, 'variants' => []];
        }

        $scored = $candidates
            ->map(fn (CatalogItem $item) => ['item' => $item, 'score' => $this->score($item, $haystack, $number, $numerator)])
            ->sortByDesc('score')
            ->values();

        $best = $scored->first();
        $second = $scored->get(1);
        $bestId = $best['item']->id;

        // Two distinct cards both fitting the title (different printings/sets that
        // share a number and name) — don't guess. Same base_key = same card's
        // variants, which the classifier's printing check handles, so allow it.
        if ($second
            && $second['score'] > 0
            && ($best['score'] - $second['score']) < 0.08
            && $best['item']->base_key !== $second['item']->base_key) {
            return ['item' => null, 'score' => $best['score'], 'number' => $number, 'reason' => 'ambiguous', 'best_id' => $bestId, 'variants' => []];
        }

        if ($best['score'] < $minScore) {
            return ['item' => null, 'score' => $best['score'], 'number' => $number, 'reason' => 'low_score', 'best_id' => $bestId, 'variants' => []];
        }

        // The winner's own printings, best-scoring first, for the classifier to
        // choose between. Same base_key means same card — a different edition,
        // finish or stamp of it, which is exactly what the printing gate reads.
        $variants = $scored
            ->filter(fn (array $s) => $s['item']->base_key === $best['item']->base_key)
            ->pluck('item')
            ->all();

        return ['item' => $best['item'], 'score' => $best['score'], 'number' => $number, 'reason' => 'matched', 'best_id' => $bestId, 'variants' => $variants];
    }

    /** Product-line slug => id, memoized for the life of the request/command. */
    private function productLineId(?string $slug): ?int
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return self::$lineIds[$slug] ??= ProductLine::where('slug', $slug)->value('id');
    }

    /** Score how strongly a candidate card matches the (normalized) title. 0..1. */
    private function score(CatalogItem $item, string $haystack, string $number, ?string $numerator): float
    {
        $score = 0.0;

        // Number: exact stored match is strongest; numerator-only is close.
        if (strcasecmp((string) $item->number, $number) === 0) {
            $score += 0.5;
        } elseif ($numerator !== null && $this->numerator($item->number) === $numerator) {
            $score += 0.45;
        }

        // Name: every token of the card's name should appear in the title. The
        // primary (first) token is required or name contributes nothing — a bare
        // number coincidence must not carry a match.
        $tokens = $this->tokens($item->name);
        if ($tokens !== []) {
            // Whole-word match: $haystack is space-padded, so " ex " won't hit "next".
            $hits = array_values(array_filter($tokens, fn ($t) => str_contains($haystack, ' '.$t.' ')));
            $primaryHit = str_contains($haystack, ' '.$tokens[0].' ');
            if ($primaryHit) {
                $score += 0.4 * (count($hits) / count($tokens));
            }
        }

        // Set corroboration. Weighted above the 0.08 ambiguity tie-break so that
        // naming the set actually decides between two same-numbered cards —
        // titles like "XY ANCIENT ORIGINS #97" say which printing they mean, and
        // at 0.1 that signal was too weak to separate them reliably.
        if ($item->set && $this->setInTitle($item->set->name, $item->set->code, $haystack)) {
            $score += 0.15;
        }

        return round(min(1.0, $score), 2);
    }

    /** Pull the most card-like number token from a title (numeric "238/191", or OP/promo forms). */
    private function extractNumber(string $title): ?string
    {
        if (preg_match('#\b(\d{1,4})\s*/\s*(\d{1,4})\b#', $title, $m)) {
            return $m[1].'/'.$m[2];
        }
        // Alphanumeric fractions: Trainer Gallery "TG01/TG30", Radiant
        // Collection "RC28/RC32", Galarian Gallery "GG01/GG70". These name the
        // card as precisely as a numeric fraction does, and 11% of the
        // no_number misses carried one.
        if (preg_match('#\b([A-Z]{1,3}\d{1,3})\s*/\s*[A-Z]{1,3}\d{1,3}\b#i', $title, $m)) {
            return strtoupper($m[1]);
        }
        // Lorcana / general "#218", "#24a" — an explicit card-number marker.
        // Anchored on '#' so it never grabs a year (2024) or set index ("EN 9").
        if (preg_match('/#\s*(\d{1,4}[a-z]?)\b/i', $title, $m)) {
            return strtoupper($m[1]);
        }
        // One Piece style: OP01-077, ST01-016, EB01-001. Set codes are excluded
        // so "SWSH09 Brilliant Stars" doesn't get read as a card number — that
        // mis-extraction was the whole of the `unmatched` bucket.
        if (preg_match('#\b([A-Z]{1,3})(\d{2})-(\d{2,3})\b#i', $title, $m)
            && ! in_array(strtoupper($m[1]), self::SET_CODE_PREFIXES, true)) {
            return strtoupper($m[1].$m[2].'-'.$m[3]);
        }
        // Promo / scarlet-violet alphanumerics: SVP150, SWSH284, XY182.
        if (preg_match('#\b((?:SVP|SWSH|XY|SM|SV|HGSS|BW|DP)\s?\d{1,4})\b#i', $title, $m)) {
            return strtoupper(str_replace(' ', '', $m[1]));
        }
        // Bare collector number — "Riolu 010 Black Star Promo", "Eevee EX 174
        // Scarlet & Violet Promo". Nearly half the no_number misses name the
        // card and its number with no '#' and no denominator. Guarded hard:
        // 2-4 digits (so it can't grab a "9" from noise), never a plausible
        // year, and never adjacent to a grade/HP/cert token that would make it
        // something other than a collector number.
        if (preg_match_all('/(?<![\w\/#-])(\d{2,4})(?![\w\/-])/', $title, $all, PREG_OFFSET_CAPTURE)) {
            foreach ($all[1] as [$digits, $offset]) {
                if ($this->looksLikeYear($digits) || $this->hasNumericQualifier($title, $offset, $digits)) {
                    continue;
                }

                return $digits;
            }
        }

        return null;
    }

    /** 1996-2035 reads as a print year in a card title, not a collector number. */
    private function looksLikeYear(string $digits): bool
    {
        $n = (int) $digits;

        return mb_strlen($digits) === 4 && $n >= 1996 && $n <= 2035;
    }

    /**
     * True when the number is qualified by a neighbouring word that makes it
     * something else — "PSA 10", "150 HP", "pop 12", "cert 68566489".
     */
    private function hasNumericQualifier(string $title, int $offset, string $digits): bool
    {
        $before = mb_strtolower(mb_substr($title, max(0, $offset - 14), min(14, $offset)));
        $after = mb_strtolower(mb_substr($title, $offset + mb_strlen($digits), 6));

        return (bool) preg_match('/\b(psa|bgs|cgc|sgc|ace|tag|ags|gem|mint|pop|cert|grade|lot|of|x)\s*$/', $before)
            || (bool) preg_match('/^\s*(hp|pop|pt|pts|cards?)\b/', $after);
    }

    /** Leading number of "238/191" → "238"; numeric forms drop leading zeros. */
    private function numerator(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $head = preg_replace('/[^0-9A-Za-z]/', '', trim(explode('/', $number)[0]));

        if ($head === '' || $head === null) {
            return null;
        }

        if (ctype_digit($head)) {
            $head = ltrim($head, '0');

            return $head === '' ? '0' : $head;
        }

        return $head;
    }

    private function setInTitle(?string $name, ?string $code, string $haystack): bool
    {
        $name = mb_strtolower(trim((string) $name));
        if ($name !== '' && mb_strlen($name) >= 3 && str_contains($haystack, $name)) {
            return true;
        }

        // Codes must match as a whole word and be >= 3 chars. A 2-char code was
        // matching as a substring, so every card in the "XY" set collected the
        // set bonus from any title containing "xy" — including "XY Evolutions",
        // a different set entirely. Now that the bonus can decide a tie, that
        // imprecision would do real damage.
        $code = mb_strtolower(trim((string) $code));

        return $code !== ''
            && mb_strlen($code) >= 3
            && str_contains($haystack, ' '.$code.' ');
    }

    private function normalize(string $value): string
    {
        return ' '.trim((string) preg_replace('/\s+/', ' ', mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $value)))).' ';
    }

    /**
     * @return array<int, string>
     */
    private function tokens(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/i', mb_strtolower($value)) ?: [],
            fn ($t) => mb_strlen($t) >= 2,
        )));
    }
}
