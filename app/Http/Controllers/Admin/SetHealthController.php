<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\LikeTerm;
use App\Support\Catalog\TcgcsvClient;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Set health (admin). One row per set answering "is this set actually complete",
 * and the two controls that fix it.
 *
 * The catalog is built from several sources and they describe cards to very
 * different depths. One Piece arrived from a price-only feed and carried no
 * rarity and no type at all until it was backfilled; old Japanese Pokémon sets
 * still have no collector numbers. None of that was visible anywhere — you had
 * to go looking set by set — which is what made the catalog feel messy without
 * it being obvious why.
 *
 * The fix for most of it is the same two steps, so both live here: point a set
 * at its TCGplayer group, then pull the facts down. Sets whose names have
 * drifted from TCGplayer's ("Extra Booster Heroines Edition" against "Extra
 * Booster: One Piece Heroines") can never be matched automatically without
 * risking a wrong pairing — "Starter Deck 16: Uta" scores 95% against "Starter
 * Deck 11: Uta" — so they are listed with candidates for a person to choose.
 */
class SetHealthController extends Controller
{
    /** At or above this a set is described well enough not to need attention. */
    private const GOOD = 90;

    /** Sort key => order-by expression, whitelisted because it reaches raw SQL. */
    private const SORTS = [
        'health' => 'health',
        'items' => 'items',
        'rarity' => 'rarity_pct',
        'type' => 'type_pct',
        'image' => 'image_pct',
        'number' => 'number_pct',
        'value' => 'value_pct',
        'name' => 'sets.name',
        'released' => 'sets.released_at',
    ];

    public function index(Request $request): Response
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'brand' => trim((string) $request->query('brand', '')),
            'language' => trim((string) $request->query('language', '')),
            'only' => in_array($request->query('only'), ['problems', 'unlinked'], true)
                ? (string) $request->query('only')
                : '',
            'sort' => array_key_exists((string) $request->query('sort'), self::SORTS)
                ? (string) $request->query('sort')
                : 'health',
            'direction' => $request->query('direction') === 'desc' ? 'desc' : 'asc',
        ];

        $paginator = Set::query()
            ->leftJoin('product_lines as pl', 'pl.id', 'sets.product_line_id')
            // One aggregate pass over the items rather than a handful of counts
            // per set — this page lists every set, and the old set admin issues
            // three queries each.
            ->leftJoinSub($this->itemStats(), 'st', 'st.set_id', 'sets.id')
            ->leftJoinSub($this->valueStats(), 'vs', 'vs.set_id', 'sets.id')
            ->when($filters['brand'] !== '', fn (Builder $q) => $q->where('pl.slug', $filters['brand']))
            ->when($filters['language'] !== '', fn (Builder $q) => $q->where('sets.language', $filters['language']))
            ->when($filters['q'] !== '', function (Builder $q) use ($filters) {
                $term = LikeTerm::clean($filters['q']);

                $q->where(fn (Builder $w) => $w
                    ->where('sets.name', 'like', "%{$term}%")
                    ->orWhere('sets.code', 'like', "%{$term}%")
                    ->orWhere('sets.series', 'like', "%{$term}%"));
            })
            ->when($filters['only'] === 'unlinked', fn (Builder $q) => $q
                ->whereNull(DB::raw("JSON_EXTRACT(sets.external_ids, '$.tcgplayer_group_id')")))
            ->select('sets.*', 'pl.name as brand', 'pl.slug as brand_slug')
            ->selectRaw('COALESCE(st.items, 0) as items')
            ->selectRaw('COALESCE(st.singles, 0) as singles')
            ->selectRaw($this->pct('st.with_rarity', 'st.singles').' as rarity_pct')
            ->selectRaw($this->pct('st.with_type', 'st.singles').' as type_pct')
            ->selectRaw($this->pct('st.with_image', 'st.items').' as image_pct')
            ->selectRaw($this->pct('st.with_number', 'st.singles').' as number_pct')
            ->selectRaw($this->pct('vs.valued', 'st.items').' as value_pct')
            // A single score so the worst sets float to the top by default.
            ->selectRaw($this->healthExpr().' as health')
            // Filtered with WHERE, not HAVING: health is a plain expression over
            // the joined subqueries, not an aggregate of this query — and a
            // HAVING on the alias breaks the paginator's count query, which
            // drops the select list and with it the alias.
            ->when($filters['only'] === 'problems', fn (Builder $q) => $q
                ->whereRaw($this->healthExpr().' < '.self::GOOD))
            ->orderByRaw(self::SORTS[$filters['sort']].' '.$filters['direction'])
            ->orderBy('sets.id')
            ->paginate(40)
            ->withQueryString();

        return Inertia::render('admin/set-health', [
            'rows' => collect($paginator->items())->map($this->row(...))->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'options' => [
                'brands' => ProductLine::orderBy('name')->get(['slug', 'name'])
                    ->map(fn (ProductLine $p) => ['value' => $p->slug, 'label' => $p->name]),
                'languages' => Set::whereNotNull('language')->distinct()
                    ->orderBy('language')->pluck('language'),
                // Only these can be linked or backfilled at all.
                'backfillable' => array_column(TcgcsvGame::cases(), 'value'),
            ],
        ]);
    }

    /** Per-set item counts, in one grouped pass. */
    private function itemStats(): \Illuminate\Database\Query\Builder
    {
        return DB::table('catalog_items')
            ->selectRaw('set_id')
            ->selectRaw('COUNT(*) as items')
            ->selectRaw("SUM(item_type = 'single') as singles")
            ->selectRaw("SUM(item_type = 'single' AND rarity IS NOT NULL) as with_rarity")
            ->selectRaw("SUM(item_type = 'single' AND JSON_EXTRACT(attributes, '$.type') IS NOT NULL) as with_type")
            ->selectRaw('SUM(primary_image_path IS NOT NULL) as with_image')
            ->selectRaw("SUM(item_type = 'single' AND number IS NOT NULL AND number <> '') as with_number")
            ->whereNotNull('set_id')
            ->groupBy('set_id');
    }

    /** Cards in each set carrying at least one market value. */
    private function valueStats(): \Illuminate\Database\Query\Builder
    {
        return DB::table('catalog_items as ci')
            ->join('market_values as mv', 'mv.catalog_item_id', 'ci.id')
            ->selectRaw('ci.set_id, COUNT(DISTINCT ci.id) as valued')
            ->whereNotNull('ci.set_id')
            ->groupBy('ci.set_id');
    }

    /** The mean of the five facets — one number for "how described is this set". */
    private function healthExpr(): string
    {
        return '('.implode(' + ', [
            $this->pct('st.with_rarity', 'st.singles'),
            $this->pct('st.with_type', 'st.singles'),
            $this->pct('st.with_image', 'st.items'),
            $this->pct('st.with_number', 'st.singles'),
            $this->pct('vs.valued', 'st.items'),
        ]).') / 5';
    }

    /** A 0–100 percentage that reads as 100 when there is nothing to cover. */
    private function pct(string $part, string $whole): string
    {
        return "(CASE WHEN COALESCE({$whole}, 0) = 0 THEN 100
                 ELSE ROUND(COALESCE({$part}, 0) * 100.0 / {$whole}) END)";
    }

    /** @return array<string, mixed> */
    private function row(Set $set): array
    {
        $groupId = $set->external_ids['tcgplayer_group_id'] ?? null;

        return [
            'id' => $set->id,
            'name' => $set->name,
            'slug' => $set->slug,
            'code' => $set->code,
            'series' => $set->series,
            'brand' => $set->getAttribute('brand'),
            'brand_slug' => $set->getAttribute('brand_slug'),
            'language' => $set->language,
            'released_at' => $set->released_at?->toDateString(),
            'items' => (int) $set->getAttribute('items'),
            'singles' => (int) $set->getAttribute('singles'),
            'coverage' => [
                'rarity' => (int) $set->getAttribute('rarity_pct'),
                'type' => (int) $set->getAttribute('type_pct'),
                'image' => (int) $set->getAttribute('image_pct'),
                'number' => (int) $set->getAttribute('number_pct'),
                'value' => (int) $set->getAttribute('value_pct'),
            ],
            'health' => (int) round((float) $set->getAttribute('health')),
            'group_id' => $groupId ? (string) $groupId : null,
            // Whether this set's brand can be linked/backfilled from TCGCSV.
            'backfillable' => TcgcsvGame::tryFrom((string) $set->getAttribute('brand_slug')) !== null,
        ];
    }

    /**
     * Candidate upstream groups for a set whose name has drifted from
     * TCGplayer's, ranked by similarity — a shortlist for a person, never an
     * automatic pairing. Names like "Starter Deck 16: Uta" and "Starter Deck 11:
     * Uta" are 95% alike and are different decks.
     */
    public function candidates(Set $set): JsonResponse
    {
        $game = TcgcsvGame::tryFrom((string) $set->productLine?->slug);

        if (! $game) {
            return response()->json(['candidates' => [], 'reason' => 'This brand is not carried by TCGCSV.']);
        }

        try {
            $groups = $this->groups($game);
        } catch (Throwable $e) {
            return response()->json(['candidates' => [], 'reason' => 'Could not reach TCGCSV: '.$e->getMessage()], 502);
        }

        $candidates = $groups
            ->map(function (array $group) use ($set) {
                similar_text(Str::lower($set->name), Str::lower((string) ($group['name'] ?? '')), $score);

                return [
                    'group_id' => (string) $group['groupId'],
                    'name' => (string) ($group['name'] ?? ''),
                    'abbreviation' => (string) ($group['abbreviation'] ?? ''),
                    'score' => round($score),
                ];
            })
            ->sortByDesc('score')
            ->take(12)
            ->values();

        return response()->json(['candidates' => $candidates]);
    }

    /** Point a set at an upstream group, or clear the link. */
    public function link(Request $request, Set $set): RedirectResponse
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'string', 'max:32'],
        ]);

        $ids = (array) ($set->external_ids ?? []);

        if (empty($data['group_id'])) {
            unset($ids['tcgplayer_group_id']);
            $message = "Cleared the group link on {$set->name}.";
        } else {
            $ids['tcgplayer_group_id'] = $data['group_id'];
            $message = "Linked {$set->name} to group {$data['group_id']}.";
        }

        $set->forceFill(['external_ids' => $ids === [] ? null : $ids])->save();

        return back()->with('success', $message);
    }

    /**
     * Pull rarity and card type for one set. Runs inline: it is a single group's
     * products, and the admin is waiting on the answer.
     */
    public function backfill(Set $set): RedirectResponse
    {
        $game = TcgcsvGame::tryFrom((string) $set->productLine?->slug);

        if (! $game) {
            return back()->with('error', "{$set->productLine?->name} is not carried by TCGCSV.");
        }

        try {
            Artisan::call('catalog:backfill-card-facts', [
                '--line' => $game->value,
                '--set' => $set->slug,
                '--execute' => true,
            ]);
        } catch (Throwable $e) {
            return back()->with('error', 'Backfill failed: '.$e->getMessage());
        }

        // The command reports what it changed; surface its own summary rather
        // than re-deriving one here.
        $output = collect(explode("\n", Artisan::output()))
            ->map(fn ($l) => trim($l))
            ->filter(fn ($l) => str_starts_with($l, 'rows updated') || str_contains($l, 'filled'))
            ->implode(' · ');

        return back()->with('success', "{$set->name}: ".($output ?: 'nothing to change.'));
    }

    /**
     * The upstream group list, cached — every candidate lookup would otherwise
     * refetch the whole category.
     *
     * Only the plain array is cached, never a Collection: the object does not
     * survive a round trip through the cache store intact, and comes back as an
     * incomplete class on the second read — so this worked on the first lookup
     * and failed on every one after it.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function groups(TcgcsvGame $game): Collection
    {
        $groups = Cache::remember(
            "tcgcsv:groups:{$game->categoryId()}",
            now()->addHours(6),
            fn () => app(TcgcsvClient::class)->groups($game->categoryId()),
        );

        return collect($groups);
    }
}
