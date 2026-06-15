<?php

namespace App\Support\Catalog;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over lorcana-api.com. The `bulk/cards` endpoint returns every
 * Lorcana card in one response (~10x faster than the filtered API, refreshed
 * twice daily) — there is no per-set endpoint, so callers fetch all cards once
 * and group by Set_ID. Free and unauthenticated.
 */
class LorcanaApiClient
{
    /**
     * Every Lorcana card, all sets, in one call.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allCards(): array
    {
        $response = $this->http()->get('bulk/cards');

        if (! $response->successful()) {
            throw new RuntimeException("lorcana-api.com bulk/cards failed: HTTP {$response->status()}");
        }

        return (array) $response->json();
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.lorcana.base_url'), '/'))
            ->timeout(120)
            ->retry(2, 2000, throw: false);
    }
}
