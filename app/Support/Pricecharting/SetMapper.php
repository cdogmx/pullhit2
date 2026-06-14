<?php

namespace App\Support\Pricecharting;

use App\Models\Set;
use Illuminate\Support\Str;

/**
 * Resolves a PriceCharting console (set name + language) to one of our catalog
 * sets. Exact normalized name match within the language, with a small curated
 * override map for the handful PriceCharting names differently. Consoles with no
 * match (Topps inserts, fast-food promos, …) resolve to null and are ignored —
 * they aren't part of our pokemontcg.io-sourced catalog.
 */
class SetMapper
{
    /** Normalized PriceCharting set name => our set slug. */
    private const OVERRIDES = [
        'baseset' => 'base',
        'baseset2' => 'base-set-2',
        'scarletviolet151' => '151',
        'expedition' => 'expedition-base-set',
    ];

    /** @var array<string, int>|null  "lang|normname" and "slug|x" => set_id */
    private ?array $index = null;

    public function resolve(string $setName, string $language): ?int
    {
        $this->ensureIndex();
        $norm = self::norm($setName);

        if (isset(self::OVERRIDES[$norm])) {
            return $this->index['slug|'.self::OVERRIDES[$norm]] ?? null;
        }

        return $this->index[$language.'|'.$norm] ?? null;
    }

    private function ensureIndex(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];
        foreach (Set::query()->get(['id', 'slug', 'name', 'language']) as $set) {
            $this->index[($set->language ?? 'en').'|'.self::norm($set->name)] = $set->id;
            $this->index['slug|'.$set->slug] = $set->id;
        }
    }

    private static function norm(string $s): string
    {
        // Fold accents (Pokémon → Pokemon) before stripping non-alphanumerics.
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($s)));
    }
}
