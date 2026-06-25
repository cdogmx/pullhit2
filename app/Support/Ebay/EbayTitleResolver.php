<?php

namespace App\Support\Ebay;

use App\Models\CatalogItem;
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
     * @return array{item: ?CatalogItem, score: float, number: ?string, reason: string, best_id: ?int}
     */
    public function resolve(string $title, ?string $language, float $minScore): array
    {
        $number = $this->extractNumber($title);

        if ($number === null) {
            return ['item' => null, 'score' => 0.0, 'number' => null, 'reason' => 'no_number', 'best_id' => null];
        }

        $numerator = $this->numerator($number);
        $haystack = $this->normalize($title);

        $candidates = CatalogItem::query()
            ->with(['set:id,name,code'])
            ->when($language, fn (Builder $q) => $q->where('language', $language))
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
            return ['item' => null, 'score' => 0.0, 'number' => $number, 'reason' => 'unmatched', 'best_id' => null];
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
            return ['item' => null, 'score' => $best['score'], 'number' => $number, 'reason' => 'ambiguous', 'best_id' => $bestId];
        }

        if ($best['score'] < $minScore) {
            return ['item' => null, 'score' => $best['score'], 'number' => $number, 'reason' => 'low_score', 'best_id' => $bestId];
        }

        return ['item' => $best['item'], 'score' => $best['score'], 'number' => $number, 'reason' => 'matched', 'best_id' => $bestId];
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

        // Set corroboration — a shared set name nudges the right printing up.
        if ($item->set && $this->setInTitle($item->set->name, $item->set->code, $haystack)) {
            $score += 0.1;
        }

        return round(min(1.0, $score), 2);
    }

    /** Pull the most card-like number token from a title (numeric "238/191", or OP/promo forms). */
    private function extractNumber(string $title): ?string
    {
        if (preg_match('#\b(\d{1,4})\s*/\s*(\d{1,4})\b#', $title, $m)) {
            return $m[1].'/'.$m[2];
        }
        // Lorcana / general "#218", "#24a" — an explicit card-number marker.
        // Anchored on '#' so it never grabs a year (2024) or set index ("EN 9").
        if (preg_match('/#\s*(\d{1,4}[a-z]?)\b/i', $title, $m)) {
            return strtoupper($m[1]);
        }
        // One Piece style: OP01-077, ST01-016, EB01-001.
        if (preg_match('#\b([A-Z]{1,3}\d{2}-\d{2,3})\b#i', $title, $m)) {
            return strtoupper($m[1]);
        }
        // Promo / scarlet-violet alphanumerics: SVP150, SWSH284, XY182.
        if (preg_match('#\b((?:SVP|SWSH|XY|SM|SV|HGSS|BW|DP)\s?\d{1,4})\b#i', $title, $m)) {
            return strtoupper(str_replace(' ', '', $m[1]));
        }

        return null;
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

        $code = mb_strtolower(trim((string) $code));

        return $code !== '' && mb_strlen($code) >= 2 && str_contains($haystack, $code);
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
