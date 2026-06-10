<?php

namespace App\Support\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the pokemontcg.io v2 API. Fetches set metadata and all cards
 * in a set (auto-paged). The optional API key (config('services.pokemontcg.key'))
 * raises rate limits and is never logged.
 */
class PokemonTcgClient
{
    private const PAGE_SIZE = 250;

    /** @return array<string, mixed> the set object */
    public function set(string $id): array
    {
        $response = $this->http()->get("sets/{$id}");

        if (! $response->successful()) {
            throw new RuntimeException("pokemontcg.io set [{$id}] failed: HTTP {$response->status()}");
        }

        return (array) $response->json('data', []);
    }

    /**
     * All cards in a set, following pagination.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cards(string $setId): array
    {
        $all = [];

        for ($page = 1; ; $page++) {
            $response = $this->http()->get('cards', [
                'q' => "set.id:{$setId}",
                'page' => $page,
                'pageSize' => self::PAGE_SIZE,
                'orderBy' => 'number',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException("pokemontcg.io cards [{$setId}] failed: HTTP {$response->status()}");
            }

            $batch = (array) $response->json('data', []);
            $all = array_merge($all, $batch);

            if (count($batch) < self::PAGE_SIZE) {
                break;
            }
        }

        return $all;
    }

    protected function http(): PendingRequest
    {
        $config = config('services.pokemontcg');

        $request = Http::baseUrl(rtrim($config['base_url'], '/'))
            ->timeout(60)
            ->retry(2, 2000, throw: false);

        return $config['key']
            ? $request->withHeaders(['X-Api-Key' => $config['key']])
            : $request;
    }
}
