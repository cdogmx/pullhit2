<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\LikeTerm;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Public type-ahead suggestions, grouped brand → set → card. Three small,
 * limited LIKE queries (one per tier), each with a thumbnail and a canonical URL
 * so the header search can link straight to the right page.
 *
 * Typo tolerance: when an exact LIKE pass is thin, a fuzzy fallback widens the
 * net with a SOUNDEX/first-letters prefilter (cheap, indexed-ish) and then ranks
 * the candidates by edit distance in PHP — so "charizrd" still finds Charizard.
 * Read-only.
 */
class SuggestSearch
{
    /** Don't query on a stray keystroke; matches the browse search threshold. */
    public const MIN_LENGTH = 2;

    /** Below this length a fuzzy match is too noisy to be useful. */
    private const FUZZY_MIN_LENGTH = 4;

    /**
     * Type-ahead cache TTL. The header/browse boxes fire on nearly every
     * keystroke, so the same prefixes ("c", "ch", "cha", …) recur constantly
     * within a session and across users. A short cache turns those into instant
     * hits and, more importantly, shields the shared cloud DB from a burst of
     * heavy LIKE scans while someone types. The catalog changes slowly, so a few
     * minutes of staleness in suggestions is invisible.
     */
    private const CACHE_TTL = 300;

    /** Filler words people add that aren't in any card/set name (mirrors SearchCatalog). */
    private const STOPWORDS = ['set', 'sets', 'card', 'cards', 'the'];

    private const BRAND_LIMIT = 5;

    private const SET_LIMIT = 6;

    private const CARD_LIMIT = 8;

    /**
     * @return array{brands: array<int, array<string, mixed>>, sets: array<int, array<string, mixed>>, cards: array<int, array<string, mixed>>}
     */
    public function __invoke(string $q): array
    {
        $q = trim($q);

        if (mb_strlen($q) < self::MIN_LENGTH) {
            return ['brands' => [], 'sets' => [], 'cards' => []];
        }

        return Cache::remember(
            'suggest:v1:'.mb_strtolower($q),
            self::CACHE_TTL,
            fn () => [
                'brands' => $this->brands($q),
                'sets' => $this->sets($q),
                'cards' => $this->cards($q),
            ],
        );
    }

    /**
     * The single best "did you mean" correction for a query — the closest card,
     * set, or brand name by edit distance — or null when the query isn't a
     * fuzzable typo or nothing is close enough. Used on a zero-result search page.
     */
    public function didYouMean(string $q): ?string
    {
        $q = trim($q);
        if (! $this->fuzzable($q)) {
            return null;
        }

        $best = null;
        $bestDist = PHP_INT_MAX;

        $consider = function (iterable $names) use ($q, &$best, &$bestDist) {
            foreach ($names as $name) {
                $dist = $this->distance($q, (string) $name);
                if ($dist !== null && $dist < $bestDist) {
                    $bestDist = $dist;
                    $best = (string) $name;
                }
            }
        };

        $consider($this->prefilter(CatalogItem::query()->where('item_type', 'single'), 'name', $q)->orderByDesc('popularity')->limit(150)->pluck('name'));
        $consider($this->prefilter(Set::query(), 'name', $q)->limit(60)->pluck('name'));
        $consider(ProductLine::pluck('name')); // tiny table — no prefilter needed

        // Only offer a real correction, and never just echo the query back.
        return $best !== null && mb_strtolower($best) !== mb_strtolower($q) ? $best : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function brands(string $q): array
    {
        $like = LikeTerm::clean($q);
        $exact = $this->toHits(
            ProductLine::query()
                ->where('name', 'like', '%'.$like.'%')
                ->addSelect(['thumb' => $this->cardThumb('product_line_id', 'product_lines.id')])
                ->orderBy('name')
                ->limit(self::BRAND_LIMIT)
                ->get(),
            fn (ProductLine $line) => $this->brandHit($line),
        );

        // Brands are a tiny table — fuzzy-rank the whole list, no prefilter needed.
        if (count($exact) < self::BRAND_LIMIT && $this->fuzzable($q)) {
            $candidates = ProductLine::query()
                ->addSelect(['thumb' => $this->cardThumb('product_line_id', 'product_lines.id')])
                ->get();

            $exact = $this->mergeFuzzy($exact, $candidates, fn (ProductLine $l) => $l->name, fn (ProductLine $l) => $this->brandHit($l), $q, self::BRAND_LIMIT);
        }

        return $exact;
    }

    /** @return array<int, array<string, mixed>> */
    private function sets(string $q): array
    {
        $base = fn () => Set::query()
            ->with('productLine:id,slug,name')
            ->addSelect(['thumb' => $this->cardThumb('set_id', 'sets.id')]);

        $like = LikeTerm::clean($q);
        $exact = $this->toHits(
            $base()
                ->where(fn (Builder $w) => $w->where('name', 'like', '%'.$like.'%')->orWhere('code', 'like', '%'.$like.'%'))
                ->orderByDesc('released_at')
                ->limit(self::SET_LIMIT)
                ->get()
                ->filter(fn (Set $set) => $set->productLine !== null),
            fn (Set $set) => $this->setHit($set),
        );

        if (count($exact) < self::SET_LIMIT && $this->fuzzable($q)) {
            $candidates = $this->prefilter($base(), 'sets.name', $q)
                ->limit(80)
                ->get()
                ->filter(fn (Set $set) => $set->productLine !== null);

            $exact = $this->mergeFuzzy($exact, $candidates, fn (Set $s) => $s->name, fn (Set $s) => $this->setHit($s), $q, self::SET_LIMIT);
        }

        return $exact;
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(string $q): array
    {
        $base = fn () => CatalogItem::query()
            ->with(['productLine:id,slug', 'set:id,slug,name']);

        // Token-AND across name OR set name OR number, so a "card + set" query
        // like "pikachu ascended heroes" matches (the set words live on `sets`,
        // not the card name). Mirrors the /browse search.
        $tokens = $this->tokens($q);

        $exact = $this->toHits(
            $base()
                ->where(function (Builder $outer) use ($tokens) {
                    foreach ($tokens as $token) {
                        $outer->where(fn (Builder $w) => $w
                            ->where('name', 'like', "%{$token}%")
                            ->orWhereHas('set', fn (Builder $s) => $s->where('name', 'like', "%{$token}%"))
                            ->orWhere('number', 'like', "%{$token}%"));
                    }
                })
                ->orderByDesc('popularity')
                ->orderBy('name')
                ->limit(self::CARD_LIMIT)
                ->get(),
            fn (CatalogItem $item) => $this->cardHit($item),
        );

        if (count($exact) < self::CARD_LIMIT && $this->fuzzable($q)) {
            $candidates = $this->prefilter($base(), 'name', $q)
                ->orderByDesc('popularity')
                ->limit(80)
                ->get();

            $exact = $this->mergeFuzzy($exact, $candidates, fn (CatalogItem $i) => $i->name, fn (CatalogItem $i) => $this->cardHit($i), $q, self::CARD_LIMIT);
        }

        return $exact;
    }

    /** @return array<string, mixed> */
    private function brandHit(ProductLine $line): array
    {
        return [
            'name' => $line->name,
            // Admin-set brand logo wins; otherwise a representative card image.
            'thumb' => $line->logo_path ?: $line->getAttribute('thumb'),
            'url' => "/browse/{$line->slug}",
        ];
    }

    /** @return array<string, mixed> */
    private function setHit(Set $set): array
    {
        return [
            'name' => $set->name,
            'brand' => $set->productLine->name,
            'series' => $set->series,
            'language' => $set->language,
            'code' => $set->code,
            'thumb' => $set->logo_path ?: $set->getAttribute('thumb'),
            'url' => "/browse/{$set->productLine->slug}/{$set->slug}",
        ];
    }

    /** @return array<string, mixed> */
    private function cardHit(CatalogItem $item): array
    {
        return [
            // id lets non-navigational consumers (e.g. the compare typeahead)
            // add a card by key; the header search just uses name/thumb/url.
            'id' => $item->id,
            'name' => $item->display_name,
            'set' => $item->set?->name,
            'thumb' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
            'url' => $item->path() ?? "/catalog/{$item->id}",
        ];
    }

    /**
     * Prefilter fuzzy candidates cheaply: phonetic match (SOUNDEX) OR the first
     * two letters, so a typo anywhere after the start still surfaces the row.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    private function prefilter(Builder $query, string $column, string $q): Builder
    {
        $prefix = LikeTerm::clean(mb_substr(mb_strtolower($q), 0, 2));
        // SOUNDEX widens recall (a typo anywhere after the start), but only MySQL/
        // MariaDB ship it — elsewhere (SQLite tests) fall back to the prefix.
        $soundex = in_array($query->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);

        return $query->where(function (Builder $w) use ($column, $q, $prefix, $soundex) {
            $w->where($column, 'like', $prefix.'%');

            if ($soundex) {
                $w->orWhereRaw("SOUNDEX({$column}) = SOUNDEX(?)", [$q]);
            }
        });
    }

    /**
     * Score candidates by edit distance to the query, keep those within a
     * length-scaled threshold, and merge the best into `$hits` (deduped by URL)
     * up to `$limit`. `$exact` results always rank first.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  array<int, array<string, mixed>>  $hits
     * @param  Collection<int, TModel>  $candidates
     * @param  callable(TModel): string  $nameOf
     * @param  callable(TModel): array<string, mixed>  $toHit
     * @return array<int, array<string, mixed>>
     */
    private function mergeFuzzy(array $hits, Collection $candidates, callable $nameOf, callable $toHit, string $q, int $limit): array
    {
        $seen = array_column($hits, 'url');

        $ranked = $candidates
            ->map(fn ($model) => ['model' => $model, 'dist' => $this->distance($q, $nameOf($model))])
            ->filter(fn (array $r) => $r['dist'] !== null)
            ->sortBy('dist');

        foreach ($ranked as $r) {
            if (count($hits) >= $limit) {
                break;
            }

            $hit = $toHit($r['model']);

            if (! in_array($hit['url'], $seen, true)) {
                $hits[] = $hit;
                $seen[] = $hit['url'];
            }
        }

        return $hits;
    }

    /**
     * The best edit distance between the query and a name — compared against the
     * whole name and each of its words (so "charizrd" matches "Charizard ex"),
     * or null when nothing falls within ~1 typo per 4 characters.
     */
    private function distance(string $q, string $name): ?int
    {
        $needle = mb_strtolower($q);
        $name = mb_strtolower($name);

        $best = levenshtein($needle, $name);

        foreach (preg_split('/\s+/', $name) ?: [] as $word) {
            if ($word !== '') {
                $best = min($best, levenshtein($needle, $word));
            }
        }

        $threshold = (int) floor(mb_strlen($needle) / 4) + 1;

        return $best <= $threshold ? $best : null;
    }

    /** A query long enough that a fuzzy match is meaningful (not noise). */
    private function fuzzable(string $q): bool
    {
        return mb_strlen(trim($q)) >= self::FUZZY_MIN_LENGTH;
    }

    /**
     * Split a query into lowercased search tokens, dropping filler words. Falls
     * back to the whole trimmed query if every word was filler.
     *
     * @return array<int, string>
     */
    private function tokens(string $q): array
    {
        $tokens = array_values(array_filter(
            // Strip LIKE wildcards so a stray "%"/"_" can't match every row.
            array_map(LikeTerm::clean(...), preg_split('/\s+/', mb_strtolower(trim($q))) ?: []),
            fn (string $t) => $t !== '' && ! in_array($t, self::STOPWORDS, true),
        ));

        return $tokens === [] ? [LikeTerm::clean(mb_strtolower(trim($q)))] : $tokens;
    }

    /**
     * Map a model collection to plain suggestion-hit arrays.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $models
     * @param  callable(TModel): array<string, mixed>  $toHit
     * @return array<int, array<string, mixed>>
     */
    private function toHits(Collection $models, callable $toHit): array
    {
        return $models->map($toHit)->values()->all();
    }

    /** One representative card image for the parent (brand/set) as a subquery. */
    private function cardThumb(string $fk, string $parentKey): BuilderContract
    {
        return CatalogItem::query()
            ->select('primary_image_path')
            ->whereColumn($fk, $parentKey)
            ->whereNotNull('primary_image_path')
            ->orderBy('id')
            ->limit(1);
    }
}
