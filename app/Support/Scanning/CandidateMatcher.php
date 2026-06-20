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
            // A broad name token (e.g. "vmax") matches hundreds of cards, so an
            // unordered window could drop the exact match. Pull number-matching
            // rows to the front so the right card is always scored; popularity
            // breaks ties among the name-only matches.
            ->when($numerator !== null, fn (Builder $q) => $q->orderByRaw(
                'CASE WHEN `number` = ? THEN 0 WHEN `number` LIKE ? THEN 1 ELSE 2 END',
                [$numerator, $numerator.'/%'],
            ))
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

        if ($nameTokens !== []) {
            $itemTokens = $this->tokens($item->name);
            $hits = count(array_intersect($nameTokens, $itemTokens));
            if ($hits > 0) {
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

        return ['item' => $item, 'score' => round(min(1.0, max(0.0, $score)), 2), 'reasons' => $reasons];
    }

    /** Leading number of a "029/086" style collector number. */
    protected function numerator(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $head = trim(explode('/', $number)[0]);
        $head = preg_replace('/[^0-9A-Za-z]/', '', $head);

        return $head !== '' ? $head : null;
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
