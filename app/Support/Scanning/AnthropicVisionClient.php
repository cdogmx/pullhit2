<?php

namespace App\Support\Scanning;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Anthropic Messages API for card vision. Forces structured
 * output via tool use (one tool, tool_choice that tool → read the tool_use input).
 * Credentials come from config('services.anthropic') and are never logged.
 */
class AnthropicVisionClient
{
    private const DETECT_TOOL = 'record_detected_cards';

    private const IDENTIFY_TOOL = 'record_card';

    /**
     * Bounding boxes (normalized 0–1, origin top-left) for each card in a photo.
     *
     * @return array<int, array{x: float, y: float, width: float, height: float}>
     */
    public function detectCards(string $base64, string $mediaType): array
    {
        $input = $this->parse(
            $this->call($base64, $mediaType, $this->detectTool(),
                'Detect every distinct trading card in this photo. For each card return a '.
                'bounding box as normalized coordinates (0–1, origin top-left) that fully '.
                'contains the ENTIRE card — all four corners and its outer border. Err on the '.
                'side of slightly too large rather than clipping any edge. Ignore sleeves, '.
                'binders, hands, and anything that is not a card.'),
            self::DETECT_TOOL,
        );

        return collect($input['cards'] ?? [])
            ->map(fn ($c) => $c['box'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /** Structured fields for a single card image. */
    public function identifyCard(string $base64, string $mediaType): array
    {
        return $this->parse(
            $this->call($base64, $mediaType, $this->identifyTool(), $this->identifyInstruction()),
            self::IDENTIFY_TOOL,
        );
    }

    /**
     * Identify many card images concurrently (one round-trip for a whole page).
     *
     * @param  array<int, array{base64: string, media_type: string}>  $items
     * @return array<int, array|null> parsed fields per item (null on failure), index-aligned
     */
    public function identifyMany(array $items): array
    {
        if ($items === []) {
            return [];
        }

        $responses = Http::pool(fn ($pool) => collect($items)->map(
            fn ($it, $i) => $pool->as((string) $i)
                ->withHeaders($this->headers())
                ->timeout(120)
                ->post($this->endpoint(), $this->payload(
                    $it['base64'], $it['media_type'], $this->identifyTool(), $this->identifyInstruction(),
                )),
        )->all());

        $out = [];
        foreach (array_keys($items) as $i) {
            $resp = $responses[(string) $i] ?? null;
            $out[$i] = $resp instanceof Response && $resp->successful()
                ? $this->safeParse($resp, self::IDENTIFY_TOOL)
                : null;
        }

        return $out;
    }

    protected function call(string $base64, string $mediaType, array $tool, string $instruction): Response
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(120)
            ->retry(2, 2000, throw: false)
            ->post($this->endpoint(), $this->payload($base64, $mediaType, $tool, $instruction));

        if (! $response->successful()) {
            throw new RuntimeException("Anthropic request failed: HTTP {$response->status()}");
        }

        return $response;
    }

    /** @return array<string, mixed> */
    protected function payload(string $base64, string $mediaType, array $tool, string $instruction): array
    {
        return [
            'model' => $this->config()['model'],
            'max_tokens' => 1500,
            'tools' => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mediaType, 'data' => $base64]],
                    ['type' => 'text', 'text' => $instruction],
                ],
            ]],
        ];
    }

    protected function parse(Response $response, string $toolName): array
    {
        $input = $this->safeParse($response, $toolName);

        if ($input === null) {
            throw new RuntimeException('Anthropic returned no structured output.');
        }

        return $input;
    }

    protected function safeParse(Response $response, string $toolName): ?array
    {
        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['name'] ?? null) === $toolName) {
                return (array) ($block['input'] ?? []);
            }
        }

        return null;
    }

    protected function identifyInstruction(): string
    {
        return 'Identify this trading card. Read the card name, the collector number exactly as printed '.
            '(e.g. "029/086"), and the set name. Detect the language/script: en, ja, ko, zh-CN, zh-TW, '.
            'fr, de, it, es, or pt — never guess English if the script is not Latin. If the card is in a '.
            'professional grading slab (PSA, BGS, CGC, SGC), set is_graded true and read the company and '.
            'numeric grade; otherwise is_graded false. '.
            'Detect the printing only when clearly visible, else null: edition = "first_edition" if a '.
            '"1st Edition" stamp/badge is printed beside the artwork; for a vintage Base-era card with no '.
            'drop shadow along the right edge of the artwork frame use "shadowless", with the shadow use '.
            '"unlimited"; modern cards have no edition (null). variant = "reverse_holo" if the whole card '.
            'body (not just the art) is foil, "holo" if only the artwork is foil, "normal" if not foil, '.
            'null if unsure. Give a 0–1 confidence for the overall identification.';
    }

    protected function detectTool(): array
    {
        return [
            'name' => self::DETECT_TOOL,
            'description' => 'Record the bounding box of every card found in the photo.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'cards' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'box' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'x' => ['type' => 'number'],
                                        'y' => ['type' => 'number'],
                                        'width' => ['type' => 'number'],
                                        'height' => ['type' => 'number'],
                                    ],
                                    'required' => ['x', 'y', 'width', 'height'],
                                ],
                            ],
                            'required' => ['box'],
                        ],
                    ],
                ],
                'required' => ['cards'],
            ],
        ];
    }

    protected function identifyTool(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'name' => self::IDENTIFY_TOOL,
            'description' => 'Record the identified card fields.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => $nullableString,
                    'number' => $nullableString + ['description' => 'Collector number as printed, e.g. "029/086", or null.'],
                    'set_name' => $nullableString,
                    'language' => $nullableString + ['description' => 'en, ja, ko, zh-CN, zh-TW, fr, de, it, es, pt, or null.'],
                    'edition' => $nullableString + ['description' => "'first_edition', 'shadowless', 'unlimited', or null. Only when clearly visible."],
                    'variant' => $nullableString + ['description' => "'reverse_holo', 'holo', 'normal', or null. The foil pattern."],
                    'is_graded' => ['type' => 'boolean'],
                    'grading_company' => $nullableString + ['description' => 'psa, bgs, cgc, sgc, ... or null.'],
                    'grade' => ['type' => ['number', 'null']],
                    'confidence' => ['type' => 'number', 'description' => '0 to 1.'],
                ],
                'required' => ['name', 'is_graded', 'confidence'],
            ],
        ];
    }

    /** @return array<string, string> */
    protected function headers(): array
    {
        return [
            'x-api-key' => $this->config()['key'],
            'anthropic-version' => $this->config()['version'],
            'content-type' => 'application/json',
        ];
    }

    protected function endpoint(): string
    {
        return $this->config()['endpoint'];
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        $config = config('services.anthropic');

        if (empty($config['key'])) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        return $config;
    }
}
