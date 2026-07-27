<?php

namespace App\Support\Ebay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Oxylabs Web Scraper API (realtime endpoint). Fetches a
 * public URL via the `universal` source and returns the rendered HTML. Credentials
 * come from config('services.oxylabs') and are never logged.
 *
 * This class is also the *meter*. Oxylabs bills per delivered result, so the only
 * place that knows what we actually spend is right here — one increment per
 * successful HTTP response, retries included. Callers used to increment a daily
 * counter once per logical operation, which undercounted badly: a card whose
 * fetch was retried (see EbaySoldSource::fetch) burned several billed requests
 * but moved the counter by one. Callers now only ask {@see hasBudget()} whether
 * it's worth starting; enforcement happens per request.
 */
class OxylabsClient
{
    public const BUDGET_EBAY = 'ebay';

    public const BUDGET_PRICECHARTING = 'pricecharting';

    public const BUDGET_RETAIL = 'retail';

    /** Budget name => config path holding its daily cap. */
    private const CAPS = [
        self::BUDGET_EBAY => 'valuation.ebay.daily_cap',
        self::BUDGET_PRICECHARTING => 'valuation.pricecharting.daily_cap',
        self::BUDGET_RETAIL => 'valuation.retail.daily_cap',
    ];

    /** Transport-level attempts per fetch. Only *billed* attempts count against a budget. */
    private const HTTP_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 2000;

    /**
     * Fetch a URL's HTML via Oxylabs. `render` defaults to true (headless render,
     * needed for JS-built pages like eBay). Pass false for sites that serve their
     * full content server-side AND fingerprint headless browsers — PriceCharting
     * strips its sold-listings tables when it detects a rendered/headless client,
     * so we fetch those plain.
     *
     * Every delivered result is charged to `$budget` before it is returned.
     *
     * @throws OxylabsBudgetException when the budget is spent (nothing requested)
     * @throws RuntimeException on a transport/credential failure
     */
    public function fetchHtml(
        string $url,
        string $geo = 'United States',
        bool $render = true,
        string $budget = self::BUDGET_EBAY,
    ): string {
        $config = config('services.oxylabs');

        if (empty($config['username']) || empty($config['password'])) {
            throw new RuntimeException('Oxylabs credentials are not configured.');
        }

        $payload = [
            'source' => 'universal',
            'url' => $url,
            'geo_location' => $geo,
        ];

        if ($render) {
            $payload['render'] = 'html';
        }

        // Retry by hand rather than with Http::retry() so each delivered result is
        // metered as it happens — a retry that succeeds is a second billed request.
        $response = null;

        for ($attempt = 1; $attempt <= self::HTTP_ATTEMPTS; $attempt++) {
            $this->assertBudget($budget);

            $response = Http::withBasicAuth($config['username'], $config['password'])
                ->timeout(90)
                ->post($config['endpoint'], $payload);

            if ($response->successful()) {
                // Oxylabs charges for delivered results only; failed (non-2xx)
                // requests don't consume budget, so this is the one place to bill.
                $this->charge($budget);

                return (string) ($response->json('results.0.content') ?? '');
            }

            if ($attempt < self::HTTP_ATTEMPTS) {
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        throw new RuntimeException("Oxylabs request failed: HTTP {$this->status($response)}");
    }

    /**
     * Whether `$budget` has room for at least one more request today. Callers use
     * this to skip work cheaply; it is advisory, not a reservation.
     */
    public function hasBudget(string $budget = self::BUDGET_EBAY): bool
    {
        return $this->spent($budget) < $this->cap($budget);
    }

    /** Billed requests charged to `$budget` so far today. */
    public function spent(string $budget = self::BUDGET_EBAY): int
    {
        return (int) Cache::get($this->key($budget), 0);
    }

    /** Requests left in `$budget` today (never negative). */
    public function remaining(string $budget = self::BUDGET_EBAY): int
    {
        return max(0, $this->cap($budget) - $this->spent($budget));
    }

    public function cap(string $budget = self::BUDGET_EBAY): int
    {
        $path = self::CAPS[$budget] ?? null;

        if ($path === null) {
            throw new RuntimeException("Unknown Oxylabs budget '{$budget}'.");
        }

        return (int) config($path, 0);
    }

    private function assertBudget(string $budget): void
    {
        if (! $this->hasBudget($budget)) {
            throw new OxylabsBudgetException($budget, $this->cap($budget));
        }
    }

    private function charge(string $budget): void
    {
        $key = $this->key($budget);

        Cache::add($key, 0, Carbon::now()->endOfDay());
        Cache::increment($key);
    }

    /** Same key shape the per-caller counters used, so today's tallies carry over. */
    private function key(string $budget): string
    {
        return $budget.':daily:'.Carbon::now()->toDateString();
    }

    private function status(?Response $response): string
    {
        return $response === null ? 'no response' : (string) $response->status();
    }
}
