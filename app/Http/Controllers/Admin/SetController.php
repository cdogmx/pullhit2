<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\MissingCardsReport;
use App\Http\Controllers\Controller;
use App\Jobs\ImportSetJob;
use App\Models\MarketValue;
use App\Models\Set;
use App\Support\Catalog\PokemonTcgClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin set management — list/import/sync sets and surface missing cards.
 * Imports run on the queue (a full set is minutes of fetch + image I/O).
 */
class SetController extends Controller
{
    public function index(): Response
    {
        $sets = Set::orderByDesc('released_at')->orderBy('name')->get()->map(function (Set $set) {
            $itemIds = $set->catalogItems()->pluck('id');

            return [
                'id' => $set->id,
                'name' => $set->name,
                'code' => $set->code,
                'series' => $set->series,
                'released_at' => $set->released_at?->toDateString(),
                'ptcgio_id' => $set->external_ids['ptcgio_id'] ?? null,
                'items' => $itemIds->count(),
                'valued' => MarketValue::whereIn('catalog_item_id', $itemIds)->distinct('catalog_item_id')->count('catalog_item_id'),
                'images' => $set->catalogItems()->whereNotNull('primary_image_path')->count(),
            ];
        });

        return Inertia::render('admin/sets', ['sets' => $sets]);
    }

    public function search(Request $request, PokemonTcgClient $client): JsonResponse
    {
        $results = collect($client->searchSets((string) $request->query('q', '')))
            ->map(fn ($s) => [
                'id' => $s['id'],
                'name' => $s['name'] ?? $s['id'],
                'series' => $s['series'] ?? null,
                'released_at' => $s['releaseDate'] ?? null,
                'total' => $s['total'] ?? null,
                'imported' => Set::where('slug', ($s['id'] ?? '').'-en')->exists(),
            ])
            ->all();

        return response()->json(['results' => $results]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate(['set_id' => ['required', 'string', 'max:50']]);

        ImportSetJob::dispatch($data['set_id']);

        return back()->with('success', "Import queued for {$data['set_id']}.");
    }

    public function resync(Set $set): RedirectResponse
    {
        $ptcgioId = $set->external_ids['ptcgio_id'] ?? null;
        if ($ptcgioId) {
            ImportSetJob::dispatch($ptcgioId);
        }

        return back()->with('success', "Re-sync queued for {$set->name}.");
    }

    public function missing(Set $set, MissingCardsReport $report): JsonResponse
    {
        return response()->json($report($set));
    }
}
