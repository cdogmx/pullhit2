<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Alerts\CheckStockAlerts;
use App\Enums\Retailer;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\RetailerLink;
use App\Models\TrackedProduct;
use App\Support\Social\XClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin watch list: products (optionally tied to a catalog item) with one target
 * price and many retailer links. The scheduled checker polls each link and
 * tweets per retailer. See App\Actions\Alerts\CheckStockAlerts.
 */
class StockAlertController extends Controller
{
    public function index(XClient $x): Response
    {
        $products = TrackedProduct::with(['links', 'catalogItem:id,name'])
            ->latest()
            ->get()
            ->map(fn (TrackedProduct $p) => $this->presentProduct($p));

        return Inertia::render('admin/stock-alerts', [
            'products' => $products,
            'retailers' => Retailer::options(),
            'xConfigured' => $x->configured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'target_price' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'currency' => ['required', Rule::in(['USD', 'GBP', 'EUR', 'CAD', 'JPY'])],
            'check_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            // Optional first retailer link.
            'retailer' => ['nullable', Rule::enum(Retailer::class)],
            'url' => ['nullable', 'required_with:retailer', 'url', 'max:2048'],
        ]);

        if (blank($data['name'] ?? null) && blank($data['catalog_item_id'] ?? null)) {
            return back()->withErrors(['name' => 'Give the product a name or attach a catalog item.']);
        }

        $product = TrackedProduct::create([
            'name' => $data['name'] ?? null,
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'target_price' => (int) round((float) $data['target_price'] * 100),
            'currency' => $data['currency'],
            'check_interval_minutes' => $data['check_interval_minutes'],
        ]);

        if (! empty($data['retailer'])) {
            $this->createLink($product, $data['retailer'], $data['url']);
        }

        return back()->with('success', 'Product added.');
    }

    public function update(Request $request, TrackedProduct $trackedProduct): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:160'],
            'catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'target_price' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'currency' => ['required', Rule::in(['USD', 'GBP', 'EUR', 'CAD', 'JPY'])],
            'check_interval_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
        ]);

        $trackedProduct->update([
            'name' => $data['name'] ?? null,
            'catalog_item_id' => $data['catalog_item_id'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'target_price' => (int) round((float) $data['target_price'] * 100),
            'currency' => $data['currency'],
            'check_interval_minutes' => $data['check_interval_minutes'],
        ]);

        return back()->with('success', 'Product updated.');
    }

    public function toggle(TrackedProduct $trackedProduct): RedirectResponse
    {
        $trackedProduct->update(['is_active' => ! $trackedProduct->is_active]);

        return back()->with('success', $trackedProduct->is_active ? 'Resumed.' : 'Paused.');
    }

    public function destroy(TrackedProduct $trackedProduct): RedirectResponse
    {
        $trackedProduct->delete();

        return back()->with('success', 'Product deleted.');
    }

    public function storeLink(Request $request, TrackedProduct $trackedProduct): RedirectResponse
    {
        $data = $request->validate([
            'retailer' => ['required', Rule::enum(Retailer::class)],
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $this->createLink($trackedProduct, $data['retailer'], $data['url']);

        return back()->with('success', 'Retailer link added.');
    }

    public function toggleLink(RetailerLink $retailerLink): RedirectResponse
    {
        $retailerLink->update(['is_active' => ! $retailerLink->is_active]);

        return back()->with('success', $retailerLink->is_active ? 'Link resumed.' : 'Link paused.');
    }

    public function destroyLink(RetailerLink $retailerLink): RedirectResponse
    {
        $retailerLink->delete();

        return back()->with('success', 'Link removed.');
    }

    /** Check one link now (ignores throttle); dry unless ?tweet=1. */
    public function checkLink(Request $request, RetailerLink $retailerLink, CheckStockAlerts $action): RedirectResponse
    {
        $retailerLink->load('product.catalogItem');
        $result = $action->evaluate($retailerLink, dryRun: ! $request->boolean('tweet'));

        $msg = $result['error']
            ? 'Check failed — see status.'
            : ($result['qualified'] ? 'In stock at/below target.' : 'Not qualifying right now.');

        return back()->with($result['error'] ? 'error' : 'success', $msg);
    }

    /** Typeahead for attaching a catalog item. */
    public function catalogSearch(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $items = CatalogItem::query()
            ->with('set:id,name')
            ->where(fn (Builder $b) => $b->where('name', 'like', "%{$q}%"))
            ->orderByDesc('popularity')
            ->limit(10)
            ->get();

        return response()->json($items->map(fn (CatalogItem $i) => [
            'id' => $i->id,
            'name' => $i->display_name ?? $i->name,
            'set' => $i->set?->name,
            'image_url' => $i->image_url,
        ]));
    }

    private function createLink(TrackedProduct $product, string $retailer, string $url): void
    {
        $retailerEnum = Retailer::from($retailer);

        $product->links()->create([
            'retailer' => $retailer,
            'url' => $url,
            'external_id' => $retailerEnum->externalIdFromUrl($url),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentProduct(TrackedProduct $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'catalog_item_id' => $p->catalog_item_id,
            'catalog_name' => $p->catalogItem?->name,
            'image_url' => $p->preferredImage(),
            'own_image_url' => $p->image_url,
            'target_price' => $p->target_price / 100,
            'currency' => $p->currency,
            'check_interval_minutes' => $p->check_interval_minutes,
            'is_active' => $p->is_active,
            'links' => $p->links->map(fn (RetailerLink $l) => $this->presentLink($l))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentLink(RetailerLink $l): array
    {
        return [
            'id' => $l->id,
            'retailer' => $l->retailer->value,
            'retailer_label' => $l->retailer->label(),
            'url' => $l->url,
            'external_id' => $l->external_id,
            'is_active' => $l->is_active,
            'last_checked_at' => $l->last_checked_at?->toIso8601String(),
            'last_price' => $l->last_price === null ? null : $l->last_price / 100,
            'last_in_stock' => $l->last_in_stock,
            'last_status' => $l->last_status,
            'last_title' => $l->last_title,
            'last_qualified' => $l->last_qualified,
            'last_error' => $l->last_error,
            'last_tweeted_at' => $l->last_tweeted_at?->toIso8601String(),
        ];
    }
}
