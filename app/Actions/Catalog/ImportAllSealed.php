<?php

namespace App\Actions\Catalog;

use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\TcgcsvClient;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Bulk sealed import across the catalog: for each (product line, TCGplayer
 * category) step, match every set to its TCGCSV group by name and run the
 * per-set sealed importer. Matching is conservative — a set with no single,
 * unambiguous group match is reported and skipped (never guessed onto the wrong
 * set). Dry-run first to review the matches.
 */
class ImportAllSealed
{
    /** Line slug => [category, set languages (null = all)]. cyberpunk isn't on TCGplayer. */
    public const PLAN = [
        ['line' => 'pokemon', 'category' => TcgcsvClient::POKEMON, 'languages' => ['en']],
        ['line' => 'pokemon', 'category' => TcgcsvClient::POKEMON_JAPAN, 'languages' => ['ja']],
        ['line' => 'one-piece', 'category' => 68, 'languages' => null],
        ['line' => 'lorcana', 'category' => 71, 'languages' => null],
    ];

    public function __construct(
        protected TcgcsvClient $tcgcsv,
        protected ImportSealedProducts $importSet,
    ) {}

    /**
     * @param  array<int, array{line: string, category: int, languages: ?array<int, string>}>  $plan
     * @param  Closure(string): void  $log
     * @return array{matched: int, unmatched: array<int, string>, sealed: int, images: int, valued: int}
     */
    public function __invoke(array $plan, bool $dryRun, bool $withImages, Closure $log): array
    {
        $matched = 0;
        $unmatched = [];
        $sealed = 0;
        $images = 0;
        $valued = 0;

        foreach ($plan as $step) {
            $line = ProductLine::where('slug', $step['line'])->first();
            if (! $line) {
                continue;
            }

            $groups = $this->indexGroups($this->tcgcsv->groups($step['category']));

            $sets = $line->sets()
                ->when($step['languages'], fn (Builder $q, array $langs) => $q->whereIn('language', $langs))
                ->orderBy('name')->get();

            $log("{$step['line']} (cat {$step['category']}): {$sets->count()} sets, ".count($groups).' TCGplayer groups');

            foreach ($sets as $set) {
                $group = $this->matchGroup($set, $groups);
                if (! $group) {
                    $unmatched[] = "{$step['line']}/{$set->name}";

                    continue;
                }

                $matched++;
                $r = ($this->importSet)($set, (int) $group['groupId'], $step['category'], $dryRun, $withImages);
                $sealed += $r['created'];
                $images += $r['images'];
                $valued += $r['valued'];

                if ($r['created'] > 0) {
                    $log(sprintf('  %-34s → %-34s  %d sealed', $set->name, $group['name'], $r['created']));
                }
            }
        }

        return ['matched' => $matched, 'unmatched' => $unmatched, 'sealed' => $sealed, 'images' => $images, 'valued' => $valued];
    }

    /**
     * Index groups by normalized name, keyed for an exact lookup plus the raw
     * list for the contains fallback.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function indexGroups(array $groups): array
    {
        return array_map(function (array $g) {
            $g['_norm'] = $this->normalize((string) ($g['name'] ?? ''));

            return $g;
        }, $groups);
    }

    /**
     * Find the one group that matches this set: an exact normalized-name match,
     * or — failing that — exactly one group whose name contains the set's name
     * (e.g. "Chaos Rising" ⊂ "ME04: Chaos Rising"). Ambiguous/none → no match.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<string, mixed>|null
     */
    private function matchGroup(Set $set, array $groups): ?array
    {
        $target = $this->normalize($set->name);
        if (mb_strlen($target) < 3) {
            return null;
        }

        $exact = array_values(array_filter($groups, fn ($g) => $g['_norm'] === $target));
        if (count($exact) === 1) {
            return $exact[0];
        }

        $contains = array_values(array_filter(
            $groups,
            fn ($g) => $g['_norm'] !== '' && str_contains($g['_norm'], $target),
        ));

        return count($contains) === 1 ? $contains[0] : null;
    }

    private function normalize(string $name): string
    {
        // Drop a leading set-code prefix ("ME04:", "SV08:") then alphanumerics only.
        $name = (string) preg_replace('/^[a-z0-9]{1,6}:\s*/i', '', trim($name));

        return Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }
}
