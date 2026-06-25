<?php

namespace App\Support\Scanning;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Extracts structured card identity from free-text listing titles via one
 * Anthropic call for a whole BATCH (many titles per request — far cheaper than
 * vision and works without an image). Forces structured output through a single
 * tool; each result is tagged with its input index so we can realign even if the
 * model reorders or drops one. Output feeds CandidateMatcher, same as a vision
 * read. Credentials come from config('services.anthropic') and are never logged.
 */
class CardTextExtractor
{
    private const TOOL = 'record_cards';

    /**
     * @param  array<int, string>  $titles  index-aligned listing titles
     * @return array<int, array<string, mixed>|null> parsed fields per title (null if the model omitted it)
     */
    public function extract(array $titles): array
    {
        if ($titles === []) {
            return [];
        }

        // Number each title so the model can echo the index back for realignment.
        $lines = [];
        foreach (array_values($titles) as $i => $t) {
            $lines[] = "[$i] $t";
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->retry(2, 2000, throw: false)
            ->post($this->config()['endpoint'], [
                'model' => $this->model(),
                'max_tokens' => 4096,
                'tools' => [$this->tool()],
                'tool_choice' => ['type' => 'tool', 'name' => self::TOOL],
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->instruction()."\n\n".implode("\n", $lines),
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

        // Realign each returned card to its input index; missing rows stay null.
        $out = array_fill(0, count($titles), null);
        foreach ((array) ($input['cards'] ?? []) as $card) {
            $i = $card['index'] ?? null;
            if (is_int($i) && $i >= 0 && $i < count($titles)) {
                $out[$i] = $card;
            }
        }

        return $out;
    }

    private function instruction(): string
    {
        return 'Each numbered line below is an eBay sold-listing title for a single trading card. '.
            'For EVERY line, extract the card identity and return one object with the matching "index". '.
            'number = the collector number as printed (e.g. "174", "029/086"), or null. '.
            'set_name = the set/expansion if present, else null. '.
            'language = en, ja, ko, zh-CN, zh-TW, fr, de, it, es, or pt — only if evident, else null. '.
            'If the title says it is in a grading slab (PSA/BGS/CGC/SGC/TAG/ACE/Beckett), set is_graded '.
            'true and read grading_company (lowercase slug; "beckett" => bgs) and the numeric grade; else '.
            'is_graded false. edition = first_edition/shadowless/unlimited only if stated, else null. '.
            'variant = reverse_holo/holo/normal only if stated, else null. confidence = 0..1 for how sure '.
            'you are of the card identity. Never invent fields that are not in the title — use null.';
    }

    /** @return array<string, mixed> */
    private function tool(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'name' => self::TOOL,
            'description' => 'Record the identity of every numbered listing title.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'cards' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer', 'description' => 'The [n] index of the input title.'],
                                'name' => $nullableString,
                                'number' => $nullableString,
                                'set_name' => $nullableString,
                                'language' => $nullableString,
                                'is_graded' => ['type' => 'boolean'],
                                'grading_company' => $nullableString,
                                'grade' => ['type' => ['number', 'null']],
                                'edition' => $nullableString,
                                'variant' => $nullableString,
                                'confidence' => ['type' => 'number'],
                            ],
                            'required' => ['index', 'name', 'is_graded', 'confidence'],
                        ],
                    ],
                ],
                'required' => ['cards'],
            ],
        ];
    }

    private function model(): string
    {
        // Defaults to the scan model; override with a cheaper one for bulk runs.
        return (string) (env('EBAY_AI_MATCH_MODEL') ?: $this->config()['model']);
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
