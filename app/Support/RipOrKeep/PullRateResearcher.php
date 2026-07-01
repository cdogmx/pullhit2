<?php

namespace App\Support\RipOrKeep;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Researches a Pokémon set's booster-pack pull rates via the Anthropic web-search
 * tool, forcing a structured, CITED result: one probability-per-pack per rarity,
 * each with a source URL. It deliberately returns nothing rather than guess when
 * a rate isn't found on the web — the rip expected-value model must stay
 * defensible. Credentials come from config('services.anthropic').
 */
class PullRateResearcher
{
    private const TOOL = 'record_pull_rates';

    /**
     * @param  array<int, string>  $rarities  the set's hit rarities to price
     * @return array<int, array{rarity: string, per_pack_prob: float, note: ?string, source: ?string, confidence: float}>
     */
    public function research(string $setName, array $rarities): array
    {
        if ($rarities === []) {
            return [];
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(180)
            ->retry(1, 2000, throw: false)
            ->post($this->config()['endpoint'], [
                'model' => $this->model(),
                'max_tokens' => 2048,
                'tools' => [
                    // Dynamic-filtering web search (Sonnet 4.6 / Opus 4.6+); filters
                    // results before they hit context. No beta header, no separate
                    // code_execution tool needed.
                    ['type' => 'web_search_20260209', 'name' => 'web_search', 'max_uses' => 6],
                    $this->recordTool(),
                ],
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->instruction($setName, $rarities),
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

        $out = [];
        foreach ((array) ($input['rates'] ?? []) as $r) {
            $rarity = trim((string) ($r['rarity'] ?? ''));
            $prob = (float) ($r['per_pack_prob'] ?? 0);

            // Keep only sane, in-vocabulary, non-zero rates — never invent one.
            if ($rarity === '' || ! in_array($rarity, $rarities, true) || $prob <= 0 || $prob > 1) {
                continue;
            }

            $out[$rarity] = [
                'rarity' => $rarity,
                'per_pack_prob' => round($prob, 6),
                'note' => isset($r['note']) ? mb_substr((string) $r['note'], 0, 4000) : null,
                'source' => isset($r['source']) ? mb_substr((string) $r['source'], 0, 1000) : null,
                'confidence' => round((float) ($r['confidence'] ?? 0.5), 2),
            ];
        }

        return array_values($out);
    }

    /** @param  array<int, string>  $rarities */
    private function instruction(string $setName, array $rarities): string
    {
        $list = implode(', ', array_map(fn ($r) => "\"{$r}\"", $rarities));

        return <<<TXT
        Research the booster-pack pull rates for the Pokémon TCG set "{$setName}".
        Use web search to find reputable data (box-break aggregates, pull-rate trackers,
        community datasets). For EACH of these rarities that you find data for, report the
        probability that a single English booster pack contains a card of that rarity:
        {$list}

        Rules:
        - Use the rarity labels EXACTLY as given above (they must match our catalog).
        - per_pack_prob is a probability between 0 and 1 (e.g. ~1 in 12 packs = 0.083).
        - Include a source URL for every rate. If you cannot find a credible source for a
          rarity, OMIT it entirely — do NOT guess or estimate from general knowledge.
        - confidence (0..1) should reflect the source quality and agreement across sources.
        Call {$this->tool_name()} with your findings.
        TXT;
    }

    private function tool_name(): string
    {
        return self::TOOL;
    }

    /** @return array<string, mixed> */
    private function recordTool(): array
    {
        return [
            'name' => self::TOOL,
            'description' => 'Record the researched per-pack pull rate for each rarity.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'rates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'rarity' => ['type' => 'string'],
                                'per_pack_prob' => ['type' => 'number'],
                                'note' => ['type' => ['string', 'null']],
                                'source' => ['type' => ['string', 'null']],
                                'confidence' => ['type' => 'number'],
                            ],
                            'required' => ['rarity', 'per_pack_prob'],
                        ],
                    ],
                ],
                'required' => ['rates'],
            ],
        ];
    }

    private function model(): string
    {
        return (string) (env('PULL_RATE_MODEL') ?: $this->config()['model']);
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
