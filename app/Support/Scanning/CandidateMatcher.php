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
            ->with(['set', 'defaultMarketValue.gradingCompany'])
            ->when($card->language, fn (Builder $q) => $q->where('language', $card->language))
            ->where(function (Builder $q) use ($numerator, $nameTokens) {
                if ($numerator !== null) {
                    $q->orWhere('number', 'like', $numerator.'/%')->orWhere('number', $numerator);
                }
                foreach ($nameTokens as $t) {
                    $q->orWhere('name', 'like', '%'.$t.'%');
                }
            })
            ->limit(50)
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

        return ['item' => $item, 'score' => round(min(1.0, $score), 2), 'reasons' => $reasons];
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
