<?php

namespace App\Support\RipOrKeep;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The CardFoo "Sensei" — a playful dojo master who rules on whether to RIP (open)
 * a sealed product or KEEP it sealed, reasoning from a real data dossier
 * (BuildSealedDossier). One non-streaming Anthropic Messages call per turn; the
 * client holds the short conversation and sends it back each time. Credentials
 * come from config('services.anthropic') and are never logged.
 */
class SenseiChat
{
    /** Keep the persona tight and the cost bounded. */
    private const MAX_TOKENS = 600;

    private const MAX_HISTORY = 10;

    /**
     * @param  array<string, mixed>  $dossier  from BuildSealedDossier
     * @param  array<int, array{role: string, content: string}>  $messages  the conversation so far
     */
    public function reply(array $dossier, array $messages): string
    {
        // Only user/assistant turns, newest window, trimmed — never trust length.
        $turns = [];
        foreach (array_slice($messages, -self::MAX_HISTORY) as $m) {
            $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($m['content'] ?? ''));
            if ($content !== '') {
                $turns[] = ['role' => $role, 'content' => mb_substr($content, 0, 1000)];
            }
        }

        if ($turns === [] || $turns[0]['role'] !== 'user') {
            array_unshift($turns, ['role' => 'user', 'content' => 'Rip or keep?']);
        }

        $response = Http::withHeaders($this->headers())
            ->timeout(60)
            ->retry(1, 1500, throw: false)
            ->post($this->config()['endpoint'], [
                'model' => $this->model(),
                'max_tokens' => self::MAX_TOKENS,
                'system' => $this->system($dossier),
                'messages' => $turns,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Anthropic request failed: HTTP {$response->status()}");
        }

        $text = '';
        foreach ((array) $response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        return trim($text) ?: 'The Sensei is meditating… ask again in a moment.';
    }

    /** @param  array<string, mixed>  $dossier */
    private function system(array $dossier): string
    {
        return <<<PROMPT
        You are the Sensei of the CardFoo dojo — a wise, playful martial-arts master who
        helps trading-card collectors decide whether to RIP (open) a sealed product or
        KEEP it sealed. CardFoo's slogan is "Wax on." (packs are "wax"). Stay in character:
        dojo/ninja flavor, a shoulder-devil "Ripper" and shoulder-angel "Keeper" whose
        cases you weigh, warm humor, light teasing. Keep replies SHORT (2–5 sentences).

        Reason from the DOSSIER below — it is real CardFoo market data, not a guess:
        - Sealed trend: is the sealed price rising, flat, or falling? A rising sealed price
          favors KEEP; a falling one weakens it.
        - In-print vs out-of-print: still buyable at retail weakens KEEP (more supply coming);
          out-of-print strengthens it.
        - Set age: older, settled sets are less likely to be reprinted.
        - Rip upside: the set's top chase singles are the dream pulls. Be HONEST — we do NOT
          have exact pull rates, so ripping is a gamble; frame the chase as a thrill, not a
          guaranteed payout. Never invent probabilities or a precise expected value.
        - The person: money-maximizer, thrill-seeker, or collector? Ask ONE short question to
          read them only if it's unclear; otherwise just rule.

        ALWAYS lead your verdict with one bold line in this exact shape (pick your conviction):
        "🥋 KEEP THE WAX (72%)" or "🥋 RIP IT OPEN (64%)" or "🥋 TOO CLOSE TO CALL (51%)".
        The percentage is YOUR conviction, not a market stat. Then 1–3 short sentences of
        dojo wisdom citing the data. End with a nudge, not financial advice.

        DOSSIER:
        {$this->dossierText($dossier)}
        PROMPT;
    }

    /** @param  array<string, mixed>  $d */
    private function dossierText(array $d): string
    {
        $money = fn (?int $c) => $c === null ? 'unknown' : '$'.number_format($c / 100, 2);
        $product = $d['product'] ?? [];
        $set = $d['set'] ?? [];
        $chase = $d['chase'] ?? [];
        $trend = $d['trend'] ?? null;

        $lines = [];
        $lines[] = 'Product: '.($product['name'] ?? 'Unknown')
            .($product['sealed_type'] ? " ({$product['sealed_type']})" : '');
        $lines[] = 'Sealed value: '.$money($d['sealed_value'] ?? null)
            .(($d['is_estimated'] ?? true) ? ' (estimated)' : '');

        $lines[] = $trend
            ? "Sealed price trend: {$trend['pct']}% over ~{$trend['days']} days ({$trend['direction']})"
            : 'Sealed price trend: not enough sold data yet';

        $lines[] = 'Set: '.($set['name'] ?? 'Unknown')
            .($set['released_at'] ? ", released {$set['released_at']}" : '')
            .($set['age_years'] !== null ? " (~{$set['age_years']} yrs old)" : '')
            .', '.(($set['in_print'] ?? false) ? 'still in print at retail' : 'appears out of print');

        $top = array_map(
            fn (array $c) => $c['name'].' '.$money($c['value']),
            array_slice($chase['top'] ?? [], 0, 6),
        );
        $lines[] = 'Rip upside — top chase singles: '.($top ? implode('; ', $top) : 'no singles data for this set');
        $lines[] = 'Chase context: '.($chase['single_count'] ?? 0).' priced singles, '
            .($chase['count_over_50'] ?? 0).' worth $50+, median single '.$money($chase['median_single'] ?? null)
            .'. (No pull rates available — the chase is a gamble.)';

        return implode("\n", $lines);
    }

    private function model(): string
    {
        return (string) (env('RIP_OR_KEEP_MODEL') ?: $this->config()['model']);
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
