<?php

namespace App\Actions\Import;

use App\Support\Import\PricechartingRow;

/**
 * Parse a PriceCharting collection-export CSV into normalized rows, ready for
 * catalog matching. Maps columns by header name (order-independent) and pulls
 * apart PriceCharting's conventions: name "[Variant] #number", a "console-name"
 * that bakes the product line + language into the set, and a grade carried in
 * the "include-string". Framework-agnostic; no DB access.
 */
class ParsePricechartingCsv
{
    /** Product-line prefixes that lead PriceCharting's "console-name". */
    private const PRODUCT_LINES = ['Pokemon', 'One Piece', 'Magic', 'Yu-Gi-Oh', 'Yugioh', 'Lorcana', 'Digimon', 'Dragon Ball'];

    /** Language words PriceCharting prefixes onto non-English sets. */
    private const LANGUAGES = [
        'Japanese' => 'ja', 'Chinese' => 'zh', 'Korean' => 'ko',
        'German' => 'de', 'French' => 'fr', 'Italian' => 'it', 'Spanish' => 'es',
    ];

    /**
     * @return array<int, PricechartingRow>
     */
    public function __invoke(string $csv): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];

        if (count($lines) < 2) {
            return [];
        }

        $header = array_map(
            fn ($h) => strtolower(trim((string) $h)),
            str_getcsv((string) array_shift($lines), ',', '"', '\\'),
        );
        $index = array_flip($header);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cols = str_getcsv($line, ',', '"', '\\');
            $get = fn (string $key): string => isset($index[$key]) ? trim((string) ($cols[$index[$key]] ?? '')) : '';

            $row = $this->parseRow($get);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  callable(string): string  $get
     */
    private function parseRow(callable $get): ?PricechartingRow
    {
        $product = $get('product-name');
        $console = $get('console-name');

        if ($product === '' || $console === '') {
            return null;
        }

        [$name, $number, $variant] = $this->parseProduct($product);
        [$productLine, $language, $setName] = $this->parseConsole($console);
        [$condition, $company, $grade] = $this->parseState($get('include-string'), $get('grading-company'));

        return new PricechartingRow(
            externalId: $get('id'),
            name: $name,
            number: $number,
            variant: $variant,
            setName: $setName,
            language: $language,
            productLine: $productLine,
            condition: $condition,
            gradingCompany: $company,
            grade: $grade,
            quantity: max(1, (int) $get('quantity')),
            costBasisCents: max(0, (int) $get('cost-basis-in-pennies')),
            folder: $get('folder') !== '' ? $get('folder') : null,
            notes: $get('notes') !== '' ? $get('notes') : null,
            acquiredAt: $get('date-purchased') !== '' ? $get('date-purchased') : null,
        );
    }

    /**
     * "Samurott [Reverse] #23" => ["Samurott", "23", "reverse"].
     *
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function parseProduct(string $product): array
    {
        $variant = null;
        if (preg_match('/\[([^\]]+)\]/', $product, $m)) {
            $variant = strtolower(trim($m[1]));
            $product = trim(str_replace($m[0], '', $product));
        }

        $number = null;
        if (preg_match('/#\s*([A-Za-z0-9\-\/]+)\s*$/', $product, $m)) {
            $number = $m[1];
            $product = trim((string) preg_replace('/#\s*[A-Za-z0-9\-\/]+\s*$/', '', $product));
        }

        return [trim($product), $number, $variant];
    }

    /**
     * "Pokemon Japanese Nihil Zero" => ["pokemon", "ja", "Nihil Zero"].
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

    /**
     * include-string (+ grading-company fallback) => [condition, company, grade].
     *
     * @return array{0: ?string, 1: ?string, 2: ?float}
     */
    private function parseState(string $include, string $company): array
    {
        $company = strtolower($company) ?: null;

        // "CGC 10", "PSA 9.5" — company + grade inline.
        if (preg_match('/^(psa|bgs|cgc|sgc|tag|ace)\s*(\d+(?:\.\d)?)$/i', $include, $m)) {
            return [null, strtolower($m[1]), (float) $m[2]];
        }

        // "Graded 8" — grade only; company (if any) comes from the column.
        if (preg_match('/^graded\s*(\d+(?:\.\d)?)$/i', $include, $m)) {
            return [null, $company, (float) $m[1]];
        }

        // Ungraded / blank — raw. PriceCharting has no NM/LP granularity, so
        // default to Near Mint and let the user adjust.
        return ['NM', null, null];
    }
}
