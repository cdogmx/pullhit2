<?php

namespace App\Http\Controllers\Web;

use App\Actions\Valuation\BuildPriceRace;
use App\Actions\Valuation\ResolveRaceSources;
use App\Http\Controllers\Controller;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A set's first weeks as a running race. Defaults to whatever is featured, so
 * the link on the home page never needs updating when the next set lands.
 */
class PriceRaceController extends Controller
{
    /** The featured set, or a named one. */
    public function show(BuildPriceRace $build, ResolveRaceSources $resolve, ?string $set = null): Response
    {
        $model = $set
            ? Set::where('slug', $set)->firstOrFail()
            : Set::query()->featured()->first();

        abort_unless($model, 404);

        return $this->render($build, $resolve, [['type' => 'set', 'slug' => $model->slug]], $model->name);
    }

    /**
     * An ad-hoc race over a whole brand or series, addressable without saving
     * anything: /price-race/brand/pokemon, /price-race/series/Mega Evolution.
     */
    public function scope(BuildPriceRace $build, ResolveRaceSources $resolve, string $scope, string $value): Response
    {
        [$source, $title] = match ($scope) {
            'set' => [['type' => 'set', 'slug' => $value], Set::where('slug', $value)->value('name')],
            'series' => [['type' => 'series', 'name' => $value], $value],
            'brand' => [['type' => 'brand', 'slug' => $value], ProductLine::where('slug', $value)->value('name')],
            default => abort(404),
        };

        abort_unless($title, 404);

        return $this->render($build, $resolve, [$source], $title);
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     */
    private function render(BuildPriceRace $build, ResolveRaceSources $resolve, array $sources, string $title): Response
    {
        // A day's worth of sales barely moves a seven-day trailing median, and
        // the build is the expensive part — a brand resolves fifty thousand
        // cards before it races two hundred.
        $race = Cache::remember(
            'price-race:v2:'.md5(json_encode($sources)),
            Carbon::now()->addMinutes(30),
            function () use ($build, $resolve, $sources) {
                $resolved = $resolve($sources);
                $data = $build($resolved['ids']);

                return $data ? $data + [
                    'capped' => $resolved['capped'],
                    'considered' => $resolved['considered'],
                ] : null;
            },
        );

        abort_unless($race, 404);

        return Inertia::render('price-race', ['race' => $race + ['title' => $title]]);
    }
}
