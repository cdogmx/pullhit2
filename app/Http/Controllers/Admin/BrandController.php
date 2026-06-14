<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin brand (product line) management — set a logo + description shown on the
 * smart-browse brand tiles and landing pages.
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

        return Inertia::render('admin/brands', ['brands' => $brands]);
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
}
