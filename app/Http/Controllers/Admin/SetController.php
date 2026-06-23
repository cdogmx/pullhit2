<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\AddSealedProduct;
use App\Actions\Catalog\CreateSet;
use App\Actions\Catalog\DeleteSet;
use App\Actions\Catalog\MissingCardsReport;
use App\Actions\Catalog\UpdateSealedProduct;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Jobs\ImportSetJob;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\ProductLine;
use App\Models\Set;
use App\Support\Catalog\PokemonTcgClient;
use App\Support\Verticals\Definitions\TcgVertical;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                'language' => $set->language,
                'released_at' => $set->released_at?->toDateString(),
                'ptcgio_id' => $set->external_ids['ptcgio_id'] ?? null,
                'logo_url' => $set->logo_path,
                'description' => $set->description,
                'items' => $itemIds->count(),
                'valued' => MarketValue::whereIn('catalog_item_id', $itemIds)->distinct('catalog_item_id')->count('catalog_item_id'),
                'images' => $set->catalogItems()->whereNotNull('primary_image_path')->count(),
            ];
        });

        return Inertia::render('admin/sets', [
            'sets' => $sets,
            'sealedTypes' => TcgVertical::SEALED_TYPES,
            'languages' => TcgVertical::LANGUAGES,
            'productLines' => ProductLine::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreateSet $create): RedirectResponse
    {
        $data = $request->validate([
            'product_line_id' => ['required', 'integer', 'exists:product_lines,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', Rule::in(TcgVertical::LANGUAGES)],
            'series' => ['nullable', 'string', 'max:255'],
            'set_family' => ['nullable', 'string', 'max:255'],
            'released_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $productLine = ProductLine::findOrFail($data['product_line_id']);

        $set = $create($productLine, [
            'name' => $data['name'],
            'code' => ($data['code'] ?? null) ?: null,
            'language' => ($data['language'] ?? null) ?: null,
            'series' => ($data['series'] ?? null) ?: null,
            'set_family' => ($data['set_family'] ?? null) ?: null,
            'released_at' => ($data['released_at'] ?? null) ?: null,
            'description' => ($data['description'] ?? null) ?: null,
            'logo_url' => ($data['logo_url'] ?? null) ?: null,
        ]);

        return back()->with('success', "Created {$set->name}.");
    }

    public function destroy(Set $set, DeleteSet $delete): RedirectResponse
    {
        $name = $set->name;

        $delete($set);

        return back()->with('success', "Deleted {$name} and its cards.");
    }

    public function update(Request $request, Set $set): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'released_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $set->update([
            'name' => $data['name'],
            'code' => $data['code'] ?: null,
            'released_at' => $data['released_at'] ?: null,
            'description' => $data['description'] ?: null,
            'logo_path' => $data['logo_url'] ?: null,
        ]);

        return back()->with('success', "Updated {$set->name}.");
    }

    public function storeSealed(Request $request, Set $set, AddSealedProduct $add): RedirectResponse
    {
        $add($set, $this->sealedData($request));

        return back()->with('success', "Added sealed product to {$set->name}.");
    }

    public function updateSealed(Request $request, CatalogItem $catalogItem, UpdateSealedProduct $update): RedirectResponse
    {
        abort_unless($catalogItem->item_type === ItemType::Sealed, 404);

        $update($catalogItem, $this->sealedData($request));

        return back()->with('success', 'Sealed product updated.');
    }

    /**
     * Validate + normalize the sealed-product form (shared by store + update).
     *
     * @return array<string, mixed>
     */
    protected function sealedData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sealed_type' => ['required', Rule::in(TcgVertical::SEALED_TYPES)],
            'language' => ['required', Rule::in(TcgVertical::LANGUAGES)],
            'pack_count' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'image_url' => ['nullable', 'url', 'max:1000'],
            'msrp' => ['nullable', 'numeric', 'min:0'],
            'released_at' => ['nullable', 'date'],
            'retailer_links' => ['nullable', 'array', 'max:25'],
            'retailer_links.*.retailer' => ['nullable', 'string', 'max:100'],
            'retailer_links.*.url' => ['nullable', 'url', 'max:1000'],
            'retailer_links.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['price_cents'] = isset($data['price']) ? (int) round((float) $data['price'] * 100) : null;
        $data['msrp_cents'] = isset($data['msrp']) ? (int) round((float) $data['msrp'] * 100) : null;

        // Keep only complete links; convert each retailer's price to cents.
        $data['retailer_links'] = collect($data['retailer_links'] ?? [])
            ->filter(fn ($l) => ! empty($l['retailer']) && ! empty($l['url']))
            ->map(fn ($l) => [
                'retailer' => $l['retailer'],
                'url' => $l['url'],
                'price_cents' => isset($l['price']) && $l['price'] !== '' && $l['price'] !== null
                    ? (int) round((float) $l['price'] * 100)
                    : null,
            ])
            ->values()
            ->all();

        return $data;
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
