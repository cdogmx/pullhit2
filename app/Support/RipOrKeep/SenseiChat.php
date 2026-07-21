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
        return ($dossier['kind'] ?? 'sealed') === 'grade'
            ? $this->gradeSystem($dossier)
            : $this->sealedSystem($dossier);
    }

    /** @param  array<string, mixed> $dossier */
    private function sealedSystem(array $dossier): string
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
        - Rip upside: if a MODELED RIP EV is given, use it — it's the expected value of
          opening, from researched pack odds. Compare it to the sealed price: if EV is well
          below sealed, keeping is the value play; if EV rivals or beats sealed, ripping is
          defensible. Still frame variance honestly (most packs miss; the EV is an average).
          If NO rip EV is given, we lack pull rates — call ripping a gamble and never invent
          probabilities.
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
            .($chase['count_over_50'] ?? 0).' worth $50+, median single '.$money($chase['median_single'] ?? null).'.';

        $ev = $d['rip_ev'] ?? null;
        if ($ev) {
            $lines[] = 'MODELED RIP EV: ~'.$money($ev['ev_per_pack'] ?? null).'/pack'
                .($ev['ev_total'] ? ', ~'.$money($ev['ev_total']).' for the whole product ('.$ev['packs'].' packs)' : '')
                .' — from researched pack odds × mean value of each chase rarity. This is an'
                .' average across many opens; any single pack usually misses.';
        } else {
            $lines[] = 'MODELED RIP EV: not available — no researched pull rates for this set, so ripping is a gamble.';
        }

        return implode("\n", $lines);
    }

    /** @param  array<string, mixed> $dossier */
    private function gradeSystem(array $dossier): string
    {
        return <<<PROMPT
        You are the Sensei of the CardFoo dojo — a wise, playful martial-arts master who
        helps trading-card collectors decide whether to GRADE a raw single (send it to PSA)
        or SELL IT RAW as-is. CardFoo's slogan is "Wax on." Stay in character: dojo/ninja
        flavor, warm humor, light teasing. Keep replies SHORT (2–5 sentences).

        Reason from the DOSSIER below — it is real CardFoo market data, not a guess:
        - Compare the raw (Near Mint) value with the graded values (PSA 10/9/8) and the
          grading cost. The BREAK-EVEN is the key honest anchor: it's the PSA-10 chance
          needed for grading to beat selling raw. Frame your verdict around it — e.g.
          "you'd need at least a 1-in-3 shot at a 10."
        - You do NOT know this specific copy's condition. The break-even and EV in the
          dossier use a NEUTRAL prior. To sharpen the call, ask ONE short question about
          the card's condition — centering, corners, edges, surface, whitening — then
          reason: crisp corners + dead-centered = higher 10 odds (lean GRADE); any obvious
          flaw = lower odds (lean SELL RAW). Never claim to know the grade; reason in odds.
        - Note when a graded value is ESTIMATED (modeled, not real comps) or the raw value
          is thin/low-confidence — temper your conviction accordingly.
        - A falling raw price slightly favors grading sooner; a rising one is fine either way.

        ALWAYS lead your verdict with one bold line in this exact shape (pick your conviction):
        "🥋 GRADE IT (68%)" or "🥋 SELL IT RAW (61%)" or "🥋 TOO CLOSE TO CALL (51%)".
        The percentage is YOUR conviction, not a market stat. Then 1–3 short sentences citing
        the data (break-even, the premium, the cost). End with a nudge, not financial advice.
        Grading also carries risk you should mention lightly: fees are sunk, turnaround is
        weeks, and a low grade can be worth LESS than the raw card.

        DOSSIER:
        {$this->gradeDossierText($dossier)}
        PROMPT;
    }

    /** @param  array<string, mixed> $d */
    private function gradeDossierText(array $d): string
    {
        $money = fn (?int $c) => $c === null ? 'unknown' : '$'.number_format($c / 100, 2);
        $pct = fn (?float $p) => $p === null ? 'n/a' : round($p * 100).'%';
        $card = $d['card'] ?? [];
        $raw = $d['raw'] ?? null;
        $graded = $d['graded'] ?? [];
        $costs = $d['costs'] ?? [];
        $advice = $d['advice'] ?? null;

        $lines = [];
        $lines[] = 'Card: '.($card['name'] ?? 'Unknown')
            .($card['number'] ? " #{$card['number']}" : '')
            .($card['set'] ? " — {$card['set']}" : '')
            .($card['rarity'] ? " ({$card['rarity']})" : '');

        $trend = $raw && $raw['trend_30d'] !== null ? ", 30-day trend {$raw['trend_30d']}%" : '';
        $lines[] = 'Raw (Near Mint) value: '.$money($raw['value'] ?? null)
            .($raw ? ' from '.($raw['n_sales'] ?? 0).' sales'.($raw['is_estimated'] ? ' (estimated)' : '').$trend : '');

        foreach (['10', '9', '8'] as $g) {
            $row = $graded[$g] ?? null;
            if ($row && $row['value'] !== null) {
                $tag = $row['estimated'] ? ' (estimated)' : ' from '.($row['n_sales'] ?? 0).' sales';
                $lines[] = "PSA {$g} value: ".$money($row['value']).$tag;
            }
        }

        $lines[] = 'Grading cost: '.$money(($costs['fee'] ?? 0) + ($costs['shipping'] ?? 0))
            .' (fee + shipping), plus ~'.round((float) ($costs['sale_fee_pct'] ?? 0) * 100).'% marketplace fee on the sale.';

        if ($advice) {
            $lines[] = 'BREAK-EVEN: you need at least a '.$pct($advice['breakeven_p10'] ?? null)
                .' chance at a PSA 10 for grading to beat selling raw.';
            $lines[] = 'At a neutral prior, expected value of grading is '.$money($advice['ev_grade'] ?? null)
                .' vs selling raw '.$money($advice['ev_raw'] ?? null)
                .' (edge '.$money($advice['advantage'] ?? null).'). Model verdict: '.($advice['verdict'] ?? 'unknown').'.';
        } else {
            $lines[] = 'BREAK-EVEN: not computable — we lack a raw value and/or a PSA 10 comp for this card. Say so honestly.';
        }

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
