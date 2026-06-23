<?php

namespace App\Support\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over TCGCSV (a free public JSON mirror of TCGplayer's API). Used
 * for Japanese Pokémon (category 85) — sets/cards/prices pokemontcg.io doesn't
 * carry — and English Pokémon (category 3) to backfill late-arriving secret
 * rares pokemontcg.io is slow to add. Endpoints return a `{results: [...]}`
 * envelope. Pass $category to target a different TCGplayer category.
 */
class TcgcsvClient
{
    /** TCGplayer category id for Japanese Pokémon. */
    public const POKEMON_JAPAN = 85;

    /** TCGplayer category id for English Pokémon. */
    public const POKEMON = 3;

    /** @return array<int, array<string, mixed>> the category's groups (sets) */
    public function groups(int $category = self::POKEMON_JAPAN): array
    {
        return $this->get("tcgplayer/{$category}/groups");
    }

    /** @return array<int, array<string, mixed>> products (cards) in a group */
    public function products(int $groupId, int $category = self::POKEMON_JAPAN): array
    {
        return $this->get("tcgplayer/{$category}/{$groupId}/products");
    }

    /** @return array<int, array<string, mixed>> price rows (one per product × subtype) */
    public function prices(int $groupId, int $category = self::POKEMON_JAPAN): array
    {
        return $this->get("tcgplayer/{$category}/{$groupId}/prices");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get(string $path): array
    {
        $response = $this->http()->get($path);

        if (! $response->successful()) {
            throw new RuntimeException("tcgcsv [{$path}] failed: HTTP {$response->status()}");
        }

        $json = $response->json();

        return $json['results'] ?? $json['data'] ?? (is_array($json) ? $json : []);
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.tcgcsv.base_url'), '/'))
            ->timeout(60)
            ->retry(2, 2000, throw: false);
    }
}
