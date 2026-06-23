<?php

namespace App\Actions\Catalog;

use App\Models\ProductLine;
use App\Models\Vertical;
use Illuminate\Support\Str;

/**
 * Create a brand (product line) under a vertical, deriving a URL slug from the
 * name that's unique within that vertical (the [vertical_id, slug] uniqueness
 * constraint). The vertical itself is never created here — it's code-coupled to
 * the Vertical registry schema, so the admin only ever picks an existing one.
 */
class CreateProductLine
{
    /**
     * @param  array<string, mixed>  $data  validated: name, description?, logo_url?
     */
    public function __invoke(Vertical $vertical, array $data): ProductLine
    {
        return ProductLine::create([
            'vertical_id' => $vertical->id,
            'slug' => $this->uniqueSlug($vertical, $data['name']),
            'name' => $data['name'],
            'logo_path' => $data['logo_url'] ?? null,
            'description' => $data['description'] ?? null,
        ]);
    }

    /** A slug from the name that doesn't collide with another brand in the vertical. */
    protected function uniqueSlug(Vertical $vertical, string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $n = 2;

        while (ProductLine::where('vertical_id', $vertical->id)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }
}
