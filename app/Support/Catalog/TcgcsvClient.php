<?php

namespace App\Support\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over TCGCSV (a free public JSON mirror of TCGplayer's API). Used
 * for the "Pokemon Japan" category (85) — Japanese sets, cards, and prices that
 * pokemontcg.io doesn't carry. Endpoints return a `{results: [...]}` envelope.
 */
class TcgcsvClient
{
    /** TCGplayer category id for Japanese Pokémon. */
    public const POKEMON_JAPAN = 85;

    /** @return array<int, array<string, mixed>> the category's groups (sets) */
    public function groups(): array
    {
        return $this->get('tcgplayer/'.self::POKEMON_JAPAN.'/groups');
    }

    /** @return array<int, array<string, mixed>> products (cards) in a group */
    public function products(int $groupId): array
    {
        return $this->get('tcgplayer/'.self::POKEMON_JAPAN."/{$groupId}/products");
    }

    /** @return array<int, array<string, mixed>> price rows (one per product × subtype) */
    public function prices(int $groupId): array
    {
        return $this->get('tcgplayer/'.self::POKEMON_JAPAN."/{$groupId}/prices");
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
