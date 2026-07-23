<?php

namespace App\Support\Ebay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @return array<int, array{title: string, price_cents: int, currency: string, image: ?string, condition: ?string, url: string, item_id: ?string}>
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

        // Request affiliate-tagged item URLs from Browse (EPN campaign id).
        $endUserCtx = ! empty($c['campaign_id'])
            ? "affiliateCampaignId={$c['campaign_id']}"
            : null;

        $response = Http::withToken($token)
            ->withHeaders(array_filter([
                'X-EBAY-C-MARKETPLACE-ID' => $c['marketplace_id'],
                'X-EBAY-C-ENDUSERCTX' => $endUserCtx,
            ]))
            ->timeout(20)
            ->retry(1, 1000, throw: false)
            ->get($c['base_url'].'/buy/browse/v1/item_summary/search', [
                'q' => $query,
                // Active Buy It Now only — asking prices for "items for sale".
                'filter' => 'buyingOptions:{FIXED_PRICE}',
                'sort' => 'price',
                'limit' => $limit,
            ]);

        if (! $response->successful()) {
            Log::warning('ebay.browse.search_failed', [
                'status' => $response->status(),
                'query' => $query,
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return [];
        }

        return collect($response->json('itemSummaries', []))
            ->map(function (array $i) {
                $rawUrl = (string) ($i['itemAffiliateWebUrl'] ?? $i['itemWebUrl'] ?? '');

                return [
                    'title' => (string) ($i['title'] ?? ''),
                    'price_cents' => (int) round(((float) ($i['price']['value'] ?? 0)) * 100),
                    'currency' => (string) ($i['price']['currency'] ?? 'USD'),
                    'image' => $i['image']['imageUrl'] ?? ($i['thumbnailImages'][0]['imageUrl'] ?? null),
                    'condition' => $i['condition'] ?? null,
                    'item_id' => isset($i['itemId']) ? (string) $i['itemId'] : null,
                    // Prefer Browse affiliate URL; always force our campid as a fallback.
                    'url' => $this->affiliateUrl($rawUrl),
                ];
            })
            ->filter(fn (array $i) => $i['url'] !== '' && $i['price_cents'] > 0)
            ->values()
            ->all();
    }

    /**
     * Ensure a listing URL carries our EPN campaign id. Browse often returns
     * itemAffiliateWebUrl when X-EBAY-C-ENDUSERCTX is set; if not (or if it's a
     * plain itemWebUrl), tag the link ourselves so every "for sale" click earns.
     */
    public function affiliateUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $campaign = (string) config('services.ebay.campaign_id', '');
        if ($campaign === '') {
            return $url;
        }

        // Already tagged with our campaign (Browse affiliate or prior tag).
        if (preg_match('/(?:[?&])(?:campid|affiliateCampaignId)='.preg_quote($campaign, '/').'\b/i', $url)) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        // Standard EPN tracking params (same as EbayAffiliate::searchUrl).
        $query['mkcid'] = $query['mkcid'] ?? '1';
        $query['mkrid'] = $query['mkrid'] ?? config('services.ebay.rover_id', '711-53200-19255-0');
        $query['siteid'] = $query['siteid'] ?? '0';
        $query['campid'] = $campaign;
        $query['toolid'] = $query['toolid'] ?? '10001';
        $query['mkevt'] = $query['mkevt'] ?? '1';

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'];
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.'://'.$host.$path.'?'.http_build_query($query).$fragment;
    }

    /** Cached app access token (client-credentials grant; eBay tokens last ~2h). */
    protected function token(): ?string
    {
        $env = (string) config('services.ebay.env', 'production');
        $cacheKey = "ebay:browse:token:{$env}";
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        $c = config('services.ebay');
        $response = Http::withBasicAuth($c['client_id'], $c['client_secret'])
            ->asForm()
            ->timeout(20)
            ->post($c['base_url'].'/identity/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
                // Base scope covers Browse item_summary/search for app tokens.
                'scope' => 'https://api.ebay.com/oauth/api_scope',
            ]);

        if (! $response->successful()) {
            Log::warning('ebay.browse.token_failed', [
                'status' => $response->status(),
                'env' => $env,
                'body' => mb_substr($response->body(), 0, 300),
            ]);

            return null; // don't cache failures
        }

        $token = (string) $response->json('access_token');
        $expires = (int) ($response->json('expires_in') ?? 7200);
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $expires - 120)));

        return $token;
    }
}
