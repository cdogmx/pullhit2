<?php

namespace App\Console\Commands;

use App\Support\Grading\CardOutline;
use App\Support\Grading\FrameWarper;
use App\Support\Grading\SurfaceAnalyzer;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Diagnostic harness for the tilt-sequence surface pipeline on REAL photos.
 *
 * Everything the pipeline has been validated against so far is synthetic, which
 * proves the maths separates constant from varying but says nothing about paper
 * texture, foil, sensor noise or hand shake. This is how those get answered.
 *
 * How to shoot the sequence:
 *   - One card, plain contrasting background, filling most of the frame.
 *   - 3–5 shots. Between each, tilt the card (or move the light) so the GLARE
 *     MOVES ACROSS THE CARD. That reflection is the entire signal — a set of
 *     evenly-lit, glare-free photos carries no surface information at all.
 *   - Keep the framing roughly constant; the warp fixes the rest.
 *
 * Then look at the --out images, not just the numbers: albedo.png should be a
 * clean, glare-free card, and detail.png should show scratches as bright lines
 * against near-black. If detail.png shows the artwork, the frames did not align.
 */
class GradingSurfaceCommand extends Command
{
    protected $signature = 'grading:surface
        {photos* : Two or more photos of the same card, tilted between shots}
        {--corners= : Override detection: "x1,y1 x2,y2 x3,y3 x4,y4" per frame, frames separated by ";"}
        {--width=500 : Rectified canvas width in pixels}
        {--max-input=1400 : Downscale inputs wider than this before processing}
        {--out= : Directory to write albedo.png, detail.png and rectified frames}';

    protected $description = 'Run the surface-damage pipeline over real photos of a card';

    public function handle(CardOutline $outline, FrameWarper $warper, SurfaceAnalyzer $analyzer): int
    {
        @ini_set('memory_limit', '1024M');

        $paths = (array) $this->argument('photos');

        if (count($paths) < 2) {
            $this->error('Need at least two photos — one image carries no specular information.');

            return self::FAILURE;
        }

        try {
            $loaded = $this->load($paths, (int) $this->option('max-input'));
            $corners = $this->corners($outline, $loaded);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        foreach ($corners as $i => $quad) {
            $this->line(sprintf('  %-28s corners %s', basename($paths[$i]), $this->fmtQuad($quad)));
        }

        $rect = $warper->rectifySequence(
            array_column($loaded, 'luma'),
            $loaded[0]['width'],
            $loaded[0]['height'],
            $corners,
            (int) $this->option('width'),
        );

        $analysis = $analyzer->analyze($rect['frames'], $rect['width'], $rect['height']);

        $this->report($analysis, $rect);

        if ($dir = $this->option('out')) {
            $this->dump($analyzer, $rect, $dir);
        }

        return self::SUCCESS;
    }

    /** @return array<int, array{luma: array<int, float>, width: int, height: int}> */
    private function load(array $paths, int $maxWidth): array
    {
        $out = [];
        $w0 = $h0 = null;

        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException("Not a file: {$path}");
            }

            $img = @imagecreatefromstring((string) file_get_contents($path));
            if ($img === false) {
                throw new RuntimeException("Could not read as an image: {$path}");
            }

            $w = imagesx($img);
            $h = imagesy($img);

            if ($w > $maxWidth) {
                $scaled = imagescale($img, $maxWidth);
                if ($scaled !== false) {
                    imagedestroy($img);
                    $img = $scaled;
                    $w = imagesx($img);
                    $h = imagesy($img);
                }
            }

            // Every frame must share a source geometry for the warper.
            $w0 ??= $w;
            $h0 ??= $h;
            if ($w !== $w0 || $h !== $h0) {
                imagedestroy($img);
                throw new RuntimeException("All photos must be the same size; {$path} is {$w}x{$h}, expected {$w0}x{$h0}.");
            }

            $luma = [];
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $rgb = imagecolorat($img, $x, $y);
                    // Rec. 601 luma — the standard perceptual weighting.
                    $luma[] = 0.299 * (($rgb >> 16) & 0xFF)
                            + 0.587 * (($rgb >> 8) & 0xFF)
                            + 0.114 * ($rgb & 0xFF);
                }
            }

            imagedestroy($img);
            $out[] = ['luma' => $luma, 'width' => $w, 'height' => $h];
        }

        return $out;
    }

    /** @return array<int, array<int, array{0: float, 1: float}>> */
    private function corners(CardOutline $outline, array $loaded): array
    {
        if ($raw = $this->option('corners')) {
            $frames = array_values(array_filter(array_map('trim', explode(';', $raw))));

            if (count($frames) !== count($loaded)) {
                throw new RuntimeException('Gave '.count($frames).' corner sets for '.count($loaded).' photos.');
            }

            return array_map(function (string $frame) {
                $points = array_values(array_filter(preg_split('/\s+/', $frame) ?: []));
                if (count($points) !== 4) {
                    throw new RuntimeException("Each frame needs four points, got: {$frame}");
                }

                return array_map(function (string $p) {
                    [$x, $y] = array_pad(explode(',', $p), 2, null);

                    return [(float) $x, (float) $y];
                }, $points);
            }, $frames);
        }

        $this->line('Detecting the card in each frame (pass --corners to override)…');

        return array_map(
            fn ($f) => $outline->detect($f['luma'], $f['width'], $f['height']),
            $loaded,
        );
    }

    private function report($analysis, array $rect): void
    {
        $this->line('');
        $this->line(sprintf('  canvas          %dx%d from %d frames', $rect['width'], $rect['height'], $analysis->framesUsed));
        $this->line(sprintf('  specular range  %.1f', $analysis->specularRange));

        if (! $analysis->isUsable()) {
            $this->line('');
            $this->warn('  UNUSABLE — the highlight barely moved between frames, so there is nothing');
            $this->warn('  to difference. This is NOT a clean card. Re-shoot, tilting more between');
            $this->warn('  shots so the glare visibly sweeps across the surface.');

            return;
        }

        $this->line(sprintf('  verdict         %s (score %d)', strtoupper($analysis->bucket), $analysis->score));
        $this->line(sprintf('  defects         %d', count($analysis->defects)));

        if ($analysis->defects !== []) {
            $this->line('');
            $this->table(
                ['x', 'y', 'length', 'elongation', 'strength'],
                array_map(fn ($d) => [
                    round($d->x), round($d->y), round($d->length), round($d->elongation, 1), round($d->strength, 1),
                ], array_slice($analysis->defects, 0, 25)),
            );

            if (count($analysis->defects) > 25) {
                $this->line(sprintf('  … and %d more', count($analysis->defects) - 25));
            }
        }
    }

    /** Write the intermediate maps so the result can be judged by eye, not just by number. */
    private function dump(SurfaceAnalyzer $analyzer, array $rect, string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}");

            return;
        }

        $maps = $analyzer->composites($rect['frames'], $rect['width'], $rect['height']);

        $this->writePng($maps['min'], $rect['width'], $rect['height'], "{$dir}/albedo.png", false);
        $this->writePng($maps['normalized'], $rect['width'], $rect['height'], "{$dir}/detail.png", true);

        foreach ($rect['frames'] as $i => $frame) {
            $this->writePng($frame, $rect['width'], $rect['height'], "{$dir}/rectified-{$i}.png", false);
        }

        $this->line('');
        $this->line("  wrote {$dir}/albedo.png (glare-free card), detail.png (defect map), rectified-*.png");
        $this->line('  albedo should look like a clean flat card; detail should be near-black except');
        $this->line('  for scratches. Artwork visible in detail.png means the frames did not align.');
    }

    /** @param  array<int, float>  $map */
    private function writePng(array $map, int $width, int $height, string $path, bool $autoScale): void
    {
        $img = imagecreatetruecolor($width, $height);

        $lo = 0.0;
        $hi = 255.0;
        if ($autoScale) {
            $lo = min($map);
            $hi = max($map);
            $hi = $hi - $lo < 1e-6 ? $lo + 1 : $hi;
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $v = ($map[$y * $width + $x] - $lo) / ($hi - $lo) * 255.0;
                $v = (int) max(0, min(255, round($v)));
                imagesetpixel($img, $x, $y, imagecolorallocate($img, $v, $v, $v));
            }
        }

        imagepng($img, $path);
        imagedestroy($img);
    }

    private function fmtQuad(array $quad): string
    {
        return implode(' ', array_map(fn ($p) => sprintf('(%d,%d)', $p[0], $p[1]), $quad));
    }
}
