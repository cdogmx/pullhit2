<?php

namespace App\Support\Pricecharting;

/**
 * Parses a PriceCharting price-guide CSV into normalized card rows for building a
 * brand-new catalog (as opposed to {@see PriceGuideParser}, which targets the
 * Pokémon reconciliation reference table). Header-mapped and generator-based so a
 * large guide never fully loads. Number extraction is driven by the game spec.
 */
class GameCatalogParser
{
    /** PriceCharting "genre" values that are sealed product, not singles. */
    private const SEALED_GENRES = ['Sealed Product'];

    public function __construct(private readonly GameImportSpec $spec) {}

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

        $isSealed = in_array($get('genre'), self::SEALED_GENRES, true);

        [$setName, $language] = $this->parseConsole($console);
        [$name, $number, $variant, $finish] = $this->parseProduct($product);

        return [
            'pc_id' => $pcId,
            'tcg_id' => $get('tcg-id') !== '' ? $get('tcg-id') : null,
            'console' => $console,
            'set_name' => $setName,
            'language' => $language,
            'name' => $name,
            'number' => $number,
            'variant' => $variant,
            'finish' => $finish,
            'is_sealed' => $isSealed,
            'release_date' => $get('release-date') !== '' ? $get('release-date') : null,
            'prices' => [
                'ungraded' => self::cents($get('loose-price')),
                'grade8' => self::cents($get('new-price')),
                'grade9' => self::cents($get('graded-price')),
                'grade95' => self::cents($get('box-only-price')),
                'psa10' => self::cents($get('manual-only-price')),
                'bgs10' => self::cents($get('bgs-10-price')),
            ],
        ];
    }

    /**
     * Strip bracket tags, then pull the collector number per the game spec.
     *
     * @return array{0: string, 1: ?string, 2: string, 3: ?string} [name, number, variant, finish]
     */
    private function parseProduct(string $product): array
    {
        $variant = 'normal';
        $finishParts = [];

        // Lift every "[Tag]" out of the product name → variant or finish.
        if (preg_match_all('/\[([^\]]+)\]/', $product, $matches)) {
            foreach ($matches[1] as $tag) {
                $tag = trim($tag);
                if (preg_match('/foil|holo/i', $tag) && ! preg_match('/reverse/i', $tag)) {
                    $variant = 'holo';
                } elseif (preg_match('/reverse/i', $tag)) {
                    $variant = 'reverse_holo';
                } else {
                    $finishParts[] = \Illuminate\Support\Str::slug($tag, '_');
                }
            }
            $product = trim((string) preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $product));
        }

        $number = null;
        if ($this->spec->numberRegex !== null
            && preg_match($this->spec->numberRegex, $product, $m)) {
            $product = trim($m['name']);
            $number = trim($m['number']);
        }

        return [
            trim($product),
            $number,
            $variant,
            $finishParts === [] ? null : implode('+', $finishParts),
        ];
    }

    /**
     * "One Piece 500 Years in the Future" => ["500 Years in the Future", "en"].
     * Strips the product-line prefix and an optional language word.
     *
     * @return array{0: string, 1: string}
     */
    private function parseConsole(string $console): array
    {
        if (stripos($console, $this->spec->consolePrefix) === 0) {
            $console = trim(substr($console, strlen($this->spec->consolePrefix)));
        }

        $language = 'en';
        $langs = ['Japanese' => 'ja', 'Korean' => 'ko', 'Chinese' => 'zh-CN', 'German' => 'de',
            'French' => 'fr', 'Italian' => 'it', 'Spanish' => 'es', 'Portuguese' => 'pt'];
        foreach ($langs as $word => $code) {
            if (stripos($console, $word.' ') === 0) {
                $language = $code;
                $console = trim(substr($console, strlen($word)));
                break;
            }
        }

        return [trim($console), $language];
    }

    /** "$1,234.56" => 123456 cents; "" => null. */
    private static function cents(string $price): ?int
    {
        $clean = preg_replace('/[^0-9.]/', '', $price);

        return ($clean === '' || $clean === null) ? null : (int) round((float) $clean * 100);
    }
}
