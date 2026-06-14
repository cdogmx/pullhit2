<?php

namespace App\Support\Pricecharting;

/**
 * Parses the PriceCharting Legendary price-guide CSV into normalized product
 * arrays (one per printing/edition/variant or sealed item), ready to upsert into
 * `pricecharting_products`. Header-mapped (column-order independent). Yields rows
 * so an 88k-line guide never loads fully into memory. Set resolution is left to
 * {@see SetMapper}; this stays framework-light (no DB).
 */
class PriceGuideParser
{
    /** Product-line prefixes PriceCharting leads "console-name" with. */
    private const PRODUCT_LINES = ['Pokemon', 'One Piece', 'Magic', 'Yu-Gi-Oh', 'Yugioh', 'Lorcana', 'Digimon', 'Dragon Ball'];

    /** Language words PriceCharting prefixes onto non-English sets. */
    private const LANGUAGES = [
        'Japanese' => 'ja', 'Korean' => 'ko', 'Chinese' => 'zh-CN',
        'German' => 'de', 'French' => 'fr', 'Italian' => 'it', 'Spanish' => 'es', 'Portuguese' => 'pt',
    ];

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function __invoke(string $csv): iterable
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            return;
        }

        $header = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        $index = array_flip($header);

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line, ',', '"', '\\');
            $get = fn (string $key): string => isset($index[$key]) ? trim((string) ($cols[$index[$key]] ?? '')) : '';

            $row = $this->parseRow($get);
            if ($row !== null) {
                yield $row;
            }
        }
    }

    /**
     * @param  callable(string): string  $get
     * @return array<string, mixed>|null
     */
    private function parseRow(callable $get): ?array
    {
        $pcId = $get('id');
        $product = $get('product-name');
        $console = $get('console-name');

        if ($pcId === '' || $product === '' || $console === '') {
            return null;
        }

        [$cardName, $number, $tag] = $this->parseProduct($product);
        [, $language, $setName] = $this->parseConsole($console);
        $facets = TagClassifier::classify($tag);

        // Every card carries a collector #number; sealed/box products don't.
        $isSealed = $number === null;

        return [
            'pc_id' => $pcId,
            'console_name' => $console,
            'product_name' => $product,
            'language' => $language,
            'set_name' => $setName,
            'card_name' => $cardName,
            'number' => $number,
            'edition' => $facets['edition'],
            'variant' => $facets['variant'],
            'finish' => $facets['finish'],
            'is_sealed' => $isSealed,
            'tcg_id' => $get('tcg-id') !== '' ? $get('tcg-id') : null,
            'price_ungraded' => self::cents($get('loose-price')),
            'price_cib' => self::cents($get('cib-price')),
            'price_grade8' => self::cents($get('new-price')),
            'price_grade9' => self::cents($get('graded-price')),
            'price_grade95' => self::cents($get('box-only-price')),
            'price_psa10' => self::cents($get('manual-only-price')),
            'price_bgs10' => self::cents($get('bgs-10-price')),
            'sales_volume' => $get('sales-volume') !== '' ? (int) $get('sales-volume') : null,
            'release_date' => $get('release-date') !== '' ? $get('release-date') : null,
        ];
    }

    /**
     * "Charizard [1st Edition] #4" => ["Charizard", "4", "1st Edition"].
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function parseProduct(string $product): array
    {
        $tag = null;
        if (preg_match('/\[([^\]]+)\]/', $product, $m)) {
            $tag = trim($m[1]);
            $product = trim(str_replace($m[0], '', $product));
        }

        $number = null;
        if (preg_match('/#\s*([A-Za-z0-9\-\/]+)\s*$/', $product, $m)) {
            $number = $m[1];
            $product = trim((string) preg_replace('/#\s*[A-Za-z0-9\-\/]+\s*$/', '', $product));
        }

        return [trim($product), $number, $tag];
    }

    /**
     * "Pokemon Korean Base Set" => ["pokemon", "ko", "Base Set"].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseConsole(string $console): array
    {
        $productLine = 'pokemon';
        foreach (self::PRODUCT_LINES as $line) {
            if (stripos($console, $line) === 0) {
                $productLine = strtolower($line);
                $console = trim(substr($console, strlen($line)));
                break;
            }
        }

        $language = 'en';
        foreach (self::LANGUAGES as $word => $code) {
            if (stripos($console, $word.' ') === 0) {
                $language = $code;
                $console = trim(substr($console, strlen($word)));
                break;
            }
        }

        return [$productLine, $language, trim($console)];
    }

    /** "$435,462.64" => 43546264 cents; "" => null. */
    private static function cents(string $price): ?int
    {
        $clean = preg_replace('/[^0-9.]/', '', $price);
        if ($clean === '' || $clean === null) {
            return null;
        }

        return (int) round((float) $clean * 100);
    }
}
