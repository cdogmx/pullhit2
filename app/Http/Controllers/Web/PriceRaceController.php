<?php

namespace App\Http\Controllers\Web;

use App\Actions\Valuation\BuildPriceRace;
use App\Http\Controllers\Controller;
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
    public function show(BuildPriceRace $build, ?string $set = null): Response
    {
        $model = $set
            ? Set::where('slug', $set)->firstOrFail()
            : Set::query()->featured()->first();

        abort_unless($model, 404);

        // A day's worth of sales barely moves a seven-day trailing median, and
        // the whole race is one query plus a fold over a few thousand rows.
        $race = Cache::remember(
            "price-race:{$model->id}:v1",
            Carbon::now()->addMinutes(30),
            fn () => $build($model),
        );

        abort_unless($race, 404);

        return Inertia::render('price-race', ['race' => $race]);
    }
}
