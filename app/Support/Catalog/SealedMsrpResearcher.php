<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sources a sealed product's original MSRP (US SRP at release) via the Anthropic
 * web-search tool, forcing a CITED result. Mirrors PullRateResearcher: it returns
 * nothing rather than guess when it can't find a credible figure for the specific
 * product — a shown MSRP must be a defensible fact, not an inference from similar
 * products. Only USD, in a sane range, is accepted. Credentials come from
 * config('services.anthropic').
 */
class SealedMsrpResearcher
{
    private const TOOL = 'record_msrp';

    /** Accept only plausible sealed MSRPs ($1–$10,000) in USD cents. */
    private const MIN_CENTS = 100;

    private const MAX_CENTS = 1_000_000;

    /**
     * @param  array{name: string, game: string, type: string, set: ?string, year: ?int, pack_count: ?int}  $product
     * @return array{msrp_cents: int, source: ?string, note: ?string, confidence: float}|null
     */
    public function research(array $product): ?array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(180)
            ->retry(1, 2000, throw: false)
            ->post($this->config()['endpoint'], [
                'model' => $this->model(),
                'max_tokens' => 1536,
                'tools' => [
                    // Dynamic-filtering web search (Sonnet 4.6 / Opus 4.6+).
                    ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 6],
                    $this->recordTool(),
                ],
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->instruction($product),
                ]],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Anthropic request failed: HTTP {$response->status()}");
        }

        $input = null;
        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === self::TOOL) {
                $input = (array) ($block['input'] ?? []);
                break;
            }
        }

        if ($input === null || empty($input['found'])) {
            return null;
        }

        $cents = (int) round((float) ($input['msrp_cents'] ?? 0));
        $currency = strtoupper((string) ($input['currency'] ?? 'USD'));

        // Reject anything not a sane USD figure — the value column + the site's
        // "% since MSRP" display both assume USD cents.
        if ($currency !== 'USD' || $cents < self::MIN_CENTS || $cents > self::MAX_CENTS) {
            return null;
        }

        return [
            'msrp_cents' => $cents,
            'source' => isset($input['source']) ? mb_substr((string) $input['source'], 0, 1000) : null,
            'note' => isset($input['note']) ? mb_substr((string) $input['note'], 0, 1000) : null,
            'confidence' => round((float) ($input['confidence'] ?? 0.5), 2),
        ];
    }

    /**
     * @param  array{name: string, game: string, type: string, set: ?string, year: ?int, pack_count: ?int}  $product
     */
    private function instruction(array $product): string
    {
        $name = $product['name'];
        $game = $product['game'];
        $type = str_replace('_', ' ', $product['type']);
        $set = $product['set'] ? "Set/expansion: {$product['set']}." : '';
        $year = $product['year'] ? "Released around {$product['year']}." : '';
        $packs = $product['pack_count'] ? "Contains {$product['pack_count']} packs." : '';

        return <<<TXT
        Find the original US MSRP (manufacturer's suggested retail price / SRP) for this
        specific sealed trading-card product at launch:

        Product: "{$name}"
        Game: {$game}
        Product type: {$type}. {$packs}
        {$set} {$year}

        Use web search to find a credible figure — the publisher's own store, a press
        release, or reputable coverage that states the MSRP/SRP (not a reseller's marked-up
        or discounted street price).

        Rules:
        - Report the price in US DOLLARS as an integer number of CENTS (e.g. $49.99 => 4999).
        - This must be the MSRP for THIS exact product, not a similar product or a different
          set. If you cannot find a credible MSRP for this specific product, set found=false
          and do NOT guess or extrapolate.
        - Always include the source URL you took the figure from.
        - confidence (0..1) should reflect source quality and how certain you are it matches
          this exact product.
        Call {$this->toolName()} with your result.
        TXT;
    }

    private function toolName(): string
    {
        return self::TOOL;
    }

    /** @return array<string, mixed> */
    private function recordTool(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Record the sourced MSRP (or that none could be found) for the product.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'found' => ['type' => 'boolean', 'description' => 'True only if a credible MSRP for this exact product was found.'],
                    'msrp_cents' => ['type' => ['integer', 'null'], 'description' => 'MSRP in US cents.'],
                    'currency' => ['type' => ['string', 'null'], 'description' => 'ISO currency code; must be USD to be stored.'],
                    'source' => ['type' => ['string', 'null'], 'description' => 'URL the MSRP was taken from.'],
                    'note' => ['type' => ['string', 'null'], 'description' => 'Brief context (e.g. "Pokémon Center listing").'],
                    'confidence' => ['type' => ['number', 'null'], 'description' => '0..1 confidence.'],
                ],
                'required' => ['found'],
            ],
        ];
    }

    private function model(): string
    {
        return (string) (env('MSRP_RESEARCH_MODEL') ?: $this->config()['model']);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'x-api-key' => $this->config()['key'],
            'anthropic-version' => $this->config()['version'],
            'content-type' => 'application/json',
        ];
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('services.anthropic');

        if (empty($config['key'])) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        return $config;
    }
}
