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
     * @return array<int, array{item: CatalogItem, score: float, reasons: array<int, string>}>
     */
    public function match(IdentifiedCard $card): array
    {
        $numerator = $this->numerator($card->number);
        $nameTokens = $this->tokens($card->name);

        if ($numerator === null && $nameTokens === []) {
            return []; // nothing to match on
        }

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
            ->where(function (Builder $q) use ($numerator, $nameTokens) {
                if ($numerator !== null) {
                    $q->orWhere('number', 'like', $numerator.'/%')->orWhere('number', $numerator);
                }
                foreach ($nameTokens as $t) {
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

        if ($numerator !== null && $item->number) {
            if ($card->number && strcasecmp($item->number, $card->number) === 0) {
                $score += 0.5;
                $reasons[] = 'number';
            } elseif ($this->numerator($item->number) === $numerator) {
                $score += 0.4;
                $reasons[] = 'number';
            }
        }

        $nameHit = false;
        if ($nameTokens !== []) {
            $itemTokens = $this->tokens($item->name);
            $hits = count(array_intersect($nameTokens, $itemTokens));
            if ($hits > 0) {
                $nameHit = true;
                $score += 0.4 * ($hits / count($nameTokens));
                $reasons[] = 'name';
            }
        }

        if ($card->setName && $item->set) {
            $setTokens = $this->tokens($item->set->name);
            if (array_intersect($this->tokens($card->setName), $setTokens) !== []) {
                $score += 0.2;
                $reasons[] = 'set';
            }
        }

        // Printing: the vision-detected edition/variant breaks the tie between
        // otherwise-identical printings (Unlimited vs 1st Edition vs Shadowless,
        // holo vs reverse). A clear mismatch is demoted so it never wins.
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

        // A read name that shares NOTHING with this item, kept alive only by a
        // number coincidence (and no set/edition corroboration), is almost always
        // the wrong card from a catalog gap. Drop it so the scanner asks the user
        // to search rather than confidently showing an unrelated card.
        if ($nameTokens !== [] && ! $nameHit
            && array_intersect(['name', 'set', 'edition'], $reasons) === []) {
            return ['item' => $item, 'score' => 0.0, 'reasons' => $reasons];
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
