<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the Netdeck.gg public card API that powers cyberpunktcg.com. Pages
 * the cyberpunk card list (limit/offset) and returns the raw card objects. One
 * call per ~100 cards; the whole game is tiny.
 */
class CyberpunkApiClient
{
    private const BASE = 'https://api.netdeck.gg/api';

    private const PAGE = 100;

    /**
     * Every cyberpunk card from the API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cards(): array
    {
        $all = [];
        $offset = 0;

        do {
            $data = $this->get($offset);
            $items = $data['items'] ?? [];
            $all = array_merge($all, $items);
            $total = (int) ($data['total'] ?? count($all));
            $offset += self::PAGE;
        } while ($items !== [] && count($all) < $total);

        return $all;
    }

    /** @return array<string, mixed> */
    private function get(int $offset): array
    {
        $response = Http::withHeaders(['User-Agent' => 'CardFooBot/1.0'])
            ->timeout(60)->retry(2, 1500, throw: false)
            ->get(self::BASE.'/cards/cyberpunk', ['limit' => self::PAGE, 'offset' => $offset]);

        if (! $response->successful()) {
            throw new RuntimeException("Netdeck API failed: HTTP {$response->status()}");
        }

        return (array) $response->json();
    }
}
