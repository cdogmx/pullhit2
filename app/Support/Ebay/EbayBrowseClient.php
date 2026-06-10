<?php

namespace App\Support\Ebay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * eBay Browse API client — live active ("buy it now") listings. Uses
 * client-credentials OAuth (app token, cached) and returns affiliate-tracked
 * URLs when an eBay Partner Network campaign id is configured. Free official API
 * (no Oxylabs cost). Returns [] when developer credentials are unset — the page
 * then falls back to the EPN affiliate search button.
 */
class EbayBrowseClient
{
    public function configured(): bool
    {
        $c = config('services.ebay');

        return ! empty($c['client_id']) && ! empty($c['client_secret']);
    }

    /**
     * @return array<int, array{title: string, price_cents: int, currency: string, image: ?string, condition: ?string, url: string}>
     */
    public function search(string $query, int $limit = 6): array
    {
        if (! $this->configured() || trim($query) === '') {
            return [];
        }

        $c = config('services.ebay');
        $token = $this->token();
        if ($token === null) {
            return [];
        }

        $endUserCtx = ! empty($c['campaign_id']) ? "affiliateCampaignId={$c['campaign_id']}" : null;

        $response = Http::withToken($token)
            ->withHeaders(array_filter([
                'X-EBAY-C-MARKETPLACE-ID' => $c['marketplace_id'],
                'X-EBAY-C-ENDUSERCTX' => $endUserCtx,
            ]))
            ->timeout(20)
            ->retry(1, 1000, throw: false)
            ->get($c['base_url'].'/buy/browse/v1/item_summary/search', [
                'q' => $query,
                'filter' => 'buyingOptions:{FIXED_PRICE}',
                'sort' => 'price',
                'limit' => $limit,
            ]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('itemSummaries', []))
            ->map(fn (array $i) => [
                'title' => (string) ($i['title'] ?? ''),
                'price_cents' => (int) round(((float) ($i['price']['value'] ?? 0)) * 100),
                'currency' => (string) ($i['price']['currency'] ?? 'USD'),
                'image' => $i['image']['imageUrl'] ?? ($i['thumbnailImages'][0]['imageUrl'] ?? null),
                'condition' => $i['condition'] ?? null,
                'url' => (string) ($i['itemAffiliateWebUrl'] ?? $i['itemWebUrl'] ?? ''),
            ])
            ->filter(fn (array $i) => $i['url'] !== '')
            ->values()
            ->all();
    }

    /** Cached app access token (client-credentials grant; eBay tokens last ~2h). */
    protected function token(): ?string
    {
        $cached = Cache::get('ebay:browse:token');
        if ($cached) {
            return $cached;
        }

        $c = config('services.ebay');
        $response = Http::withBasicAuth($c['client_id'], $c['client_secret'])
            ->asForm()
            ->timeout(20)
            ->post($c['base_url'].'/identity/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
                'scope' => 'https://api.ebay.com/oauth/api_scope',
            ]);

        if (! $response->successful()) {
            return null; // don't cache failures
        }

        $token = (string) $response->json('access_token');
        $expires = (int) ($response->json('expires_in') ?? 7200);
        Cache::put('ebay:browse:token', $token, now()->addSeconds(max(60, $expires - 120)));

        return $token;
    }
}
