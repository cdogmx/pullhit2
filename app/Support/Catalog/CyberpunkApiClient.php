<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Netdeck.gg public card API that powers cyberpunktcg.com. The
 * unfiltered list omits some sets (box toppers), and every set also has a "beta"
 * (pre-release) twin we don't want — so we read the set filter and pull each
 * RETAIL set explicitly, then merge. One call per set (the game is tiny).
 */
class CyberpunkApiClient
{
    private const BASE = 'https://api.netdeck.gg/api';

    private const PAGE = 100;

    /**
     * Every cyberpunk card across the retail sets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cards(): array
    {
        $all = [];
        foreach ($this->retailSetCodes() as $code) {
            $all = array_merge($all, $this->cardsForSet($code));
        }

        return $all;
    }

    /**
     * Retail set codes from the API's set filter (beta/pre-release sets excluded).
     *
     * @return array<int, string>
     */
    public function retailSetCodes(): array
    {
        $filters = (array) ($this->get('/cards/cyberpunk/filters')['filters'] ?? []);

        foreach ($filters as $filter) {
            if (($filter['key'] ?? null) === 'set') {
                return collect($filter['options'] ?? [])
                    ->pluck('code')
                    ->filter(fn ($code) => is_string($code) && str_contains($code, 'retail'))
                    ->values()->all();
            }
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cardsForSet(string $code): array
    {
        $all = [];
        $offset = 0;

        do {
            $data = $this->get('/cards/cyberpunk', ['set' => $code, 'limit' => self::PAGE, 'offset' => $offset]);
            $items = $data['items'] ?? [];
            $all = array_merge($all, $items);
            $total = (int) ($data['total'] ?? count($all));
            $offset += self::PAGE;
        } while ($items !== [] && count($all) < $total);

        return $all;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = Http::withHeaders(['User-Agent' => 'CardFooBot/1.0'])
            ->timeout(60)->retry(2, 1500, throw: false)
            ->get(self::BASE.$path, $query);

        if (! $response->successful()) {
            throw new RuntimeException("Netdeck API failed: HTTP {$response->status()} for {$path}");
        }

        return (array) $response->json();
    }
}
