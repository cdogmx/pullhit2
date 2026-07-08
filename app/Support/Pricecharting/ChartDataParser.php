<?php

namespace App\Support\Pricecharting;

use Carbon\CarbonImmutable;

/**
 * Parses the long-term price series embedded in a PriceCharting product page as
 * `VGPC.chart_data = {"used":[[epochMs, cents], …], "new":[…], …}`. Values are
 * already in US cents; points are ~monthly, back to release. Zero points (no
 * data that month) are dropped. Powers the card page's multi-year history line.
 *
 * PriceCharting reuses the video-game schema keys for cards; for a single they
 * map to grade tiers (for a sealed product only "used" is meaningful — the
 * sealed price):
 *   used → Ungraded   cib → Grade 7   new → Grade 8
 *   graded → Grade 9  boxonly → Grade 9.5   manualonly → PSA 10
 */
class ChartDataParser
{
    /** chart_data series key → our grade tier ("ungraded" or a grade number). */
    private const TIERS = [
        'used' => 'ungraded',
        'cib' => '7',
        'new' => '8',
        'graded' => '9',
        'boxonly' => '9.5',
        'manualonly' => '10',
    ];

    /**
     * @return array<int, array{t: string, price: int}>
     */
    public function parse(string $html, string $series = 'used'): array
    {
        $data = $this->decode($html);

        return $data === null ? [] : $this->points($data[$series] ?? null);
    }

    /**
     * Every non-empty grade tier's long-term series, keyed by tier ("ungraded",
     * "7", "8", "9", "9.5", "10").
     *
     * @return array<string, array<int, array{t: string, price: int}>>
     */
    public function parseAll(string $html): array
    {
        $data = $this->decode($html);
        if ($data === null) {
            return [];
        }

        $tiers = [];
        foreach (self::TIERS as $seriesKey => $tier) {
            $points = $this->points($data[$seriesKey] ?? null);
            if ($points !== []) {
                $tiers[$tier] = $points;
            }
        }

        return $tiers;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $html): ?array
    {
        if (! preg_match('/VGPC\.chart_data\s*=\s*(\{.*?\});/s', $html, $m)) {
            return null;
        }

        $data = json_decode($m[1], true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  mixed  $series
     * @return array<int, array{t: string, price: int}>
     */
    private function points($series): array
    {
        if (! is_array($series)) {
            return [];
        }

        $points = [];
        foreach ($series as $pair) {
            if (! is_array($pair) || count($pair) < 2) {
                continue;
            }

            $cents = (int) round((float) $pair[1]);
            if ($cents <= 0) {
                continue; // no data for that month
            }

            $points[] = [
                't' => CarbonImmutable::createFromTimestampMs((int) $pair[0])->toDateString(),
                'price' => $cents,
            ];
        }

        return $points;
    }
}
