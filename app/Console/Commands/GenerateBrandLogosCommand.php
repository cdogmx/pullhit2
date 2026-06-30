<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Geometry\Factories\PolygonFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Typography\FontFactory;

/**
 * Renders large, transparent-background PNGs of the CardFoo lockup (ninja mark +
 * "CardFoo" wordmark) in each brand ink — black, white, gold — for the brand
 * page's downloads. The mark is drawn from the same straight-line polygons as
 * AppLogoIcon, supersampled then scaled down for clean edges. Re-runnable.
 */
class GenerateBrandLogosCommand extends Command
{
    protected $signature = 'brand:logos {--height=480 : final mark height in px} {--ss=3 : supersample factor}';

    protected $description = 'Generate large transparent PNG lockups of the CardFoo logo (black/white/gold)';

    /** The ninja mark, as polygons in the 32×32 SVG viewBox (mirrors AppLogoIcon). */
    private const PATHS = [
        [[4, 12.5], [25, 13.6], [25, 16], [4, 14.9]],       // headband
        [[24, 12.9], [30.5, 10], [30.5, 12.4], [25, 15]],   // knot ribbon
        [[24.5, 15], [30, 15.4], [29.4, 17.6], [25, 16.2]], // knot ribbon
        [[7, 17.9], [14.5, 19], [14.5, 21], [7, 20.1]],     // left eye
        [[25, 17.9], [17.5, 19], [17.5, 21], [25, 20.1]],   // right eye
    ];

    // Mark bounding box within the viewBox.
    private const MIN_X = 4;
    private const MIN_Y = 10;
    private const W_UNITS = 26.5; // 30.5 - 4
    private const H_UNITS = 11;   // 21 - 10

    /** @var array<string, string> */
    private const INKS = [
        'black' => '#111317',
        'white' => '#ffffff',
        'gold' => '#cbb601',
    ];

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');

        $ss = max(1, (int) $this->option('ss'));
        $markHeightFinal = max(80, (int) $this->option('height'));
        $scale = ($markHeightFinal / self::H_UNITS) * $ss;

        $pad = 70 * $ss;
        $gap = 64 * $ss;
        $fontSize = (int) round($markHeightFinal * 1.25) * $ss;
        $font = resource_path('fonts/NotoSans-Bold.ttf');
        $word = 'CardFoo';

        $markW = self::W_UNITS * $scale;
        $markH = self::H_UNITS * $scale;

        [$textW, $textH] = $this->measure($font, $fontSize, $word);

        $contentH = (int) round(max($markH, $textH));
        $canvasW = (int) round($pad + $markW + $gap + $textW + $pad);
        $canvasH = $contentH + 2 * $pad;

        $markTop = $pad + ($contentH - $markH) / 2;
        $textX = (int) round($pad + $markW + $gap);
        $centerY = (int) round($pad + $contentH / 2);

        $manager = new ImageManager(new Driver);
        $dir = public_path('brand');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach (self::INKS as $name => $ink) {
            $canvas = $manager->createImage($canvasW, $canvasH); // transparent

            foreach (self::PATHS as $poly) {
                $canvas->drawPolygon(function (PolygonFactory $p) use ($poly, $scale, $pad, $markTop, $ink) {
                    foreach ($poly as [$x, $y]) {
                        $p->point(
                            (int) round(($x - self::MIN_X) * $scale + $pad),
                            (int) round(($y - self::MIN_Y) * $scale + $markTop),
                        );
                    }
                    $p->background($ink);
                });
            }

            $canvas->text($word, $textX, $centerY, fn (FontFactory $f) => $f
                ->filename($font)->size($fontSize)->color($ink)->align('left', 'center'));

            $final = $canvas->scaleDown(width: (int) round($canvasW / $ss));
            $path = "{$dir}/cardfoo-logo-{$name}.png";
            $final->save($path);

            $this->line("wrote {$path} ({$final->width()}×{$final->height()})");
        }

        return self::SUCCESS;
    }

    /**
     * Measure rendered text width/height in pixels for the given TTF + size.
     *
     * @return array{0: float, 1: float}
     */
    private function measure(string $font, int $size, string $text): array
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return [abs($box[2] - $box[0]), abs($box[7] - $box[1])];
    }
}
