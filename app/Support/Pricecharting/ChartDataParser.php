<?php

namespace App\Support\Pricecharting;

use Carbon\CarbonImmutable;

/**
 * Parses the long-term price series embedded in a PriceCharting product page as
 * `VGPC.chart_data = {"used":[[epochMs, cents], …], "new":[…], …}`. Values are
 * already in US cents; points are ~monthly, back to release. For a sealed product
 * the "used" series is the sealed price. Zero points (no data that month) are
 * dropped. Powers the card page's multi-year history line.
 */
class ChartDataParser
{
    /**
     * @return array<int, array{t: string, price: int}>
     */
    public function parse(string $html, string $series = 'used'): array
    {
        if (! preg_match('/VGPC\.chart_data\s*=\s*(\{.*?\});/s', $html, $m)) {
            return [];
        }

        $data = json_decode($m[1], true);
        if (! is_array($data) || ! isset($data[$series]) || ! is_array($data[$series])) {
            return [];
        }

        $points = [];
        foreach ($data[$series] as $pair) {
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
