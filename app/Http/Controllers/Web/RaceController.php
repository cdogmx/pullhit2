<?php

namespace App\Http\Controllers\Web;

use App\Actions\Valuation\BuildPriceRace;
use App\Actions\Valuation\ResolveRaceSources;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\PriceRace;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Saved price races: a named selection of cards, raced and shared by URL.
 *
 * The builder does not care whether a race came from a saved row or a scope in
 * the address bar, so both go through the same two steps — resolve the sources
 * to cards, then race them.
 */
class RaceController extends Controller
{
    /** How long a built race is worth keeping. A day of sales barely moves a
     *  seven-day trailing median, and the build is the expensive part. */
    private const CACHE_MINUTES = 30;

    /** Public races, newest first. */
    public function index(Request $request): Response
    {
        return Inertia::render('races/index', [
            'races' => PriceRace::query()
                ->where('is_public', true)
                ->with('user:id,username')
                ->latest('updated_at')
                ->limit(60)
                ->get()
                ->map(fn (PriceRace $r) => $this->card($r))
                ->all(),
            'mine' => $request->user() ? PriceRace::where('user_id', $request->user()->id)
                ->latest('updated_at')->get()->map(fn (PriceRace $r) => $this->card($r))->all() : [],
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('races/form', [
            'race' => null,
            'options' => $this->formOptions(),
        ]);
    }

    public function store(Request $request, ResolveRaceSources $resolve): RedirectResponse
    {
        $data = $this->validated($request);

        // A race nothing resolves to is a blank chart with a name on it.
        if ($resolve($data['sources'])['ids'] === []) {
            return back()->withErrors(['sources' => 'Nothing in that selection has sold yet.'])->withInput();
        }

        $race = PriceRace::create($data + ['user_id' => $request->user()->id]);

        return to_route('races.show', $race->slug)->with('success', 'Race saved.');
    }

    public function show(
        Request $request,
        PriceRace $race,
        ResolveRaceSources $resolve,
        BuildPriceRace $build,
    ): Response {
        abort_unless($race->isVisibleTo($request->user()), 404);

        $built = Cache::remember(
            "race:{$race->id}:v1:".md5(json_encode([$race->sources, $race->options])),
            Carbon::now()->addMinutes(self::CACHE_MINUTES),
            function () use ($race, $resolve, $build) {
                $resolved = $resolve($race->sources);

                $data = $build($resolved['ids'], $race->options ?? []);

                return $data ? $data + [
                    'capped' => $resolved['capped'],
                    'considered' => $resolved['considered'],
                ] : null;
            },
        );

        abort_unless($built, 404);

        $race->increment('views');

        return Inertia::render('price-race', [
            'race' => $built + [
                'title' => $race->name,
                'description' => $race->description,
                'owner' => $race->user?->username,
                'slug' => $race->slug,
                'editable' => $race->isEditableBy($request->user()),
            ],
        ]);
    }

    public function edit(Request $request, PriceRace $race): Response
    {
        abort_unless($race->isEditableBy($request->user()), 403);

        return Inertia::render('races/form', [
            'race' => [
                'slug' => $race->slug,
                'name' => $race->name,
                'description' => $race->description,
                'sources' => $this->describeSources($race->sources),
                'options' => $race->options ?? [],
                'is_public' => $race->is_public,
            ],
            'options' => $this->formOptions(),
        ]);
    }

    public function update(Request $request, PriceRace $race): RedirectResponse
    {
        abort_unless($race->isEditableBy($request->user()), 403);

        $race->update($this->validated($request));

        Cache::forget("race:{$race->id}:v1:".md5(json_encode([$race->sources, $race->options])));

        return to_route('races.show', $race->slug)->with('success', 'Race updated.');
    }

    public function destroy(Request $request, PriceRace $race): RedirectResponse
    {
        abort_unless($race->isEditableBy($request->user()), 403);

        $race->delete();

        return to_route('races.index')->with('success', 'Race deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['boolean'],
            'sources' => ['required', 'array', 'min:1', 'max:20'],
            'sources.*.type' => ['required', 'string', 'in:set,series,brand,cards'],
            'sources.*.slug' => ['nullable', 'string', 'max:120'],
            'sources.*.name' => ['nullable', 'string', 'max:120'],
            'sources.*.line' => ['nullable', 'string', 'max:64'],
            'sources.*.ids' => ['nullable', 'array', 'max:200'],
            'sources.*.ids.*' => ['integer'],
            'options.top' => ['nullable', 'integer', 'min:3', 'max:50'],
            'options.window' => ['nullable', 'integer', 'min:1', 'max:30'],
            'options.from' => ['nullable', 'date'],
            'options.to' => ['nullable', 'date'],
        ]);

        $data['options'] = array_filter($data['options'] ?? [], fn ($v) => $v !== null && $v !== '');

        return $data;
    }

    /** Sets and brands for the picker; series come from the chosen brand. */
    private function formOptions(): array
    {
        return [
            'brands' => ProductLine::orderBy('name')->get(['slug', 'name'])->all(),
            'sets' => Set::query()
                ->whereHas('catalogItems')
                ->with('productLine:id,slug,name')
                ->orderByDesc('released_at')
                ->limit(300)
                ->get()
                ->map(fn (Set $s) => [
                    'slug' => $s->slug,
                    'name' => $s->name,
                    'brand' => $s->productLine?->name,
                    'line' => $s->productLine?->slug,
                ])
                ->all(),
            'series' => Set::query()
                ->whereNotNull('series')
                ->with('productLine:id,slug,name')
                ->get()
                ->map(fn (Set $s) => ['name' => $s->series, 'line' => $s->productLine?->slug, 'brand' => $s->productLine?->name])
                ->unique(fn (array $s) => $s['line'].'|'.$s['name'])
                ->sortBy('name')
                ->values()
                ->all(),
        ];
    }

    /**
     * Sources with enough on them to render as chips — a saved card list is a
     * row of ids, and the editor needs names and pictures.
     *
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function describeSources(array $sources): array
    {
        return array_map(function (array $source) {
            if (($source['type'] ?? null) !== 'cards') {
                return $source;
            }

            $source['cards'] = CatalogItem::whereIn('id', $source['ids'] ?? [])
                ->with('set:id,name')
                ->get()
                ->map(fn (CatalogItem $i) => [
                    'id' => $i->id,
                    'name' => $i->display_name ?: $i->name,
                    'number' => $i->number,
                    'set' => $i->set?->name,
                    'thumb' => $i->primary_image_path,
                ])
                ->all();

            return $source;
        }, $sources);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(PriceRace $race): array
    {
        return [
            'slug' => $race->slug,
            'name' => $race->name,
            'description' => $race->description,
            'owner' => $race->user?->username,
            'is_public' => $race->is_public,
            'views' => $race->views,
            'sources' => count($race->sources),
            'updated_at' => $race->updated_at?->toIso8601String(),
        ];
    }
}
