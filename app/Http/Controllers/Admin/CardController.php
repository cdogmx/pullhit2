<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\UpdateCatalogItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCardRequest;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin card editor — search the catalog and fix individual cards.
 */
class CardController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $setSlug = (string) $request->query('set', '');

        $paginator = CatalogItem::query()
            ->with('set')
            ->when($q !== '', fn (Builder $query) => $query->where(
                fn (Builder $w) => $w->where('name', 'like', "%{$q}%")->orWhere('number', 'like', "%{$q}%"),
            ))
            ->when($setSlug !== '', fn (Builder $query) => $query->whereHas('set', fn (Builder $s) => $s->where('slug', $setSlug)))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/cards', [
            'items' => collect($paginator->items())->map(fn (CatalogItem $i) => $this->row($i)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'sets' => Set::orderBy('name')->get(['slug', 'name']),
            'filters' => ['q' => $q, 'set' => $setSlug],
        ]);
    }

    public function update(UpdateCardRequest $request, CatalogItem $catalogItem, UpdateCatalogItem $update): RedirectResponse
    {
        $update($catalogItem, $request->validated());

        return back()->with('success', 'Card updated.');
    }

    public function destroy(CatalogItem $catalogItem): RedirectResponse
    {
        $catalogItem->marketValues()->delete();
        $catalogItem->saleObservations()->delete();
        CollectionItem::where('catalog_item_id', $catalogItem->id)->delete();
        $catalogItem->delete();

        return back()->with('success', 'Card deleted.');
    }

    /** @return array<string, mixed> */
    protected function row(CatalogItem $item): array
    {
        $a = $item->attributes ?? [];

        return [
            'id' => $item->id,
            'name' => $item->name,
            'number' => $item->number,
            'language' => $item->language,
            'set' => $item->set?->name,
            'image_url' => $item->primary_image_path ?? ($item->external_ids['ptcgio_image'] ?? null),
            'primary_image_path' => $item->primary_image_path,
            'rarity' => $a['rarity'] ?? null,
            'variant' => $a['variant'] ?? null,
            'illustrator' => $a['illustrator'] ?? null,
            'hp' => $a['hp'] ?? null,
            'type' => $a['type'] ?? null,
        ];
    }
}
