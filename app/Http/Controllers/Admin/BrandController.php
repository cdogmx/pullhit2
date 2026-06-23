<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\CreateProductLine;
use App\Actions\Catalog\DeleteProductLine;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Vertical;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin brand (product line) management — create/edit/delete the brands shown on
 * the smart-browse brand tiles and landing pages. Verticals are code-coupled to
 * the Vertical registry schema, so they're picked from a fixed list, not created.
 */
class BrandController extends Controller
{
    public function index(): Response
    {
        $brands = ProductLine::with('vertical:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductLine $line) => [
                'id' => $line->id,
                'slug' => $line->slug,
                'name' => $line->name,
                'vertical' => $line->vertical?->name,
                'logo_url' => $line->logo_path,
                'description' => $line->description,
                'sets' => $line->sets()->count(),
                'items' => CatalogItem::where('product_line_id', $line->id)->count(),
            ]);

        return Inertia::render('admin/brands', [
            'brands' => $brands,
            'verticals' => Vertical::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, CreateProductLine $create): RedirectResponse
    {
        $data = $request->validate([
            'vertical_id' => ['required', 'integer', 'exists:verticals,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $vertical = Vertical::findOrFail($data['vertical_id']);

        $line = $create($vertical, [
            'name' => $data['name'],
            'description' => ($data['description'] ?? null) ?: null,
            'logo_url' => ($data['logo_url'] ?? null) ?: null,
        ]);

        return back()->with('success', "Created {$line->name}.");
    }

    public function update(Request $request, ProductLine $productLine): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
        ]);

        $productLine->update([
            'name' => $data['name'],
            'description' => $data['description'] ?: null,
            'logo_path' => $data['logo_url'] ?: null,
        ]);

        return back()->with('success', "Updated {$productLine->name}.");
    }

    public function destroy(ProductLine $productLine, DeleteProductLine $delete): RedirectResponse
    {
        $name = $productLine->name;

        $delete($productLine);

        return back()->with('success', "Deleted {$name} and all of its sets and cards.");
    }
}
