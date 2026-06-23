<?php

namespace App\Actions\Catalog;

use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Support\Str;

/**
 * Create a set under a brand (product line) by hand — the manual counterpart to
 * the importer. Derives a URL slug from the name, unique within the product line
 * ([product_line_id, slug]). Cards are added afterwards (import or hand-create).
 */
class CreateSet
{
    /**
     * @param  array<string, mixed>  $data  validated: name, code?, language?, series?,
     *                                       set_family?, released_at?, description?, logo_url?
     */
    public function __invoke(ProductLine $productLine, array $data): Set
    {
        return Set::create([
            'product_line_id' => $productLine->id,
            'slug' => $this->uniqueSlug($productLine, $data['name']),
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'language' => $data['language'] ?? null,
            'series' => $data['series'] ?? null,
            'set_family' => $data['set_family'] ?? null,
            'released_at' => $data['released_at'] ?? null,
            'logo_path' => $data['logo_url'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }

    /** A slug from the name that doesn't collide with another set in the product line. */
    protected function uniqueSlug(ProductLine $productLine, string $name): string
    {
        $base = Str::slug($name) ?: 'set';
        $slug = $base;
        $n = 2;

        while (Set::where('product_line_id', $productLine->id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
