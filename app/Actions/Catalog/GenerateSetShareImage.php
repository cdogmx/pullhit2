<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Models\Set;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;
use Throwable;

/**
 * Builds a 1200×630 social/OG share image for a set: a collage of its top cards
 * by value with price chips, the set name, and CardFoo branding. Stored in S3
 * and refreshed weekly (catalog:set-share-images). Returns the stored URL, or
 * null when the set has too few valued cards to make a worthwhile image.
 */
class GenerateSetShareImage
{
    private const W = 1200;

    private const H = 630;

    private const COLS = 4;

    private const ROWS = 2;

    // Matches the CardFoo lockup's background so it blends seamlessly.
    private const BG = '#111317';

    private const GOLD = '#cbb601';

    private const WHITE = '#ffffff';

    private const MUTED = '#99a4ae';

    public function __invoke(Set $set, int $minCards = 4): ?string
    {
        $cards = $this->topCards($set);

        if ($cards->count() < $minCards) {
            return null;
        }

        $manager = new ImageManager(new Driver);
        $canvas = $manager->createImage(self::W, self::H)->fill(self::BG);

        $this->header($canvas, $manager, $set);
        $this->grid($canvas, $manager, $cards);
        $this->footer($canvas, $cards->count());

        $key = "phb/og/sets/{$set->id}.jpg";
        Storage::disk('s3')->put($key, (string) $canvas->encode(new JpegEncoder(82)), 'public');
        $url = Storage::disk('s3')->url($key);

        $set->forceFill(['og_image_path' => $url, 'og_image_at' => Carbon::now()])->save();

        return $url;
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    private function topCards(Set $set)
    {
        return CatalogItem::query()
            ->where('set_id', $set->id)
            ->whereNotNull('primary_image_path')
            ->whereHas('marketValues', fn ($q) => $q->where('median', '>', 0))
            ->withMax(['marketValues as value' => fn ($q) => $q->where('median', '>', 0)], 'median')
            ->orderByDesc('value')
            ->limit(self::COLS * self::ROWS)
            ->get();
    }

    private function header(ImageInterface $canvas, ImageManager $manager, Set $set): void
    {
        $meta = implode('  ·  ', array_filter([
            $set->productLine?->name,
            $set->series,
            $set->language ? strtoupper($set->language) : null,
        ]));

        // LEFT: the set's own logo when it has one, otherwise its name as text.
        $logo = $this->remoteLogo($manager, $set->logo_path, 420, 64);
        if ($logo) {
            $canvas->insert($logo, 44, 30);
            $metaY = 106;
        } else {
            $canvas->text($this->clip($set->name, 26), 44, 38, fn (FontFactory $f) => $f
                ->filename($this->font('Bold'))->size(46)->color(self::WHITE)->align('left', 'top'));
            $metaY = 98;
        }
        $canvas->text($meta, 46, $metaY, fn (FontFactory $f) => $f
            ->filename($this->font('Regular'))->size(22)->color(self::MUTED)->align('left', 'top'));

        // RIGHT: the CardFoo lockup (gold mark + wordmark + slogan).
        $lockup = $this->lockup($manager, 62);
        if ($lockup) {
            $canvas->insert($lockup, self::W - 40 - $lockup->width(), 36);
        }
    }

    private function lockup(ImageManager $manager, int $maxHeight): ?ImageInterface
    {
        try {
            $path = public_path('cardfoo-logo.png');

            return is_file($path) ? $manager->decodePath($path)->scaleDown(height: $maxHeight) : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function remoteLogo(ImageManager $manager, ?string $url, int $maxW, int $maxH): ?ImageInterface
    {
        if (! $url) {
            return null;
        }

        try {
            $res = Http::timeout(20)->retry(2, 500, throw: false)->get($url);

            return $res->successful()
                ? $manager->decodeBinary($res->body())->scaleDown($maxW, $maxH)
                : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  Collection<int, CatalogItem>  $cards
     */
    private function grid(ImageInterface $canvas, ImageManager $manager, $cards): void
    {
        $cardW = 150;
        $cardH = 210;
        $colW = (self::W - 88) / self::COLS;
        $top = 140;

        foreach ($cards->values() as $i => $card) {
            $col = $i % self::COLS;
            $row = intdiv($i, self::COLS);
            $x = (int) (44 + $col * $colW + ($colW - $cardW) / 2);
            $y = $top + $row * ($cardH + 18);

            $img = $this->fetch($manager, (string) $card->primary_image_path, $cardW, $cardH);
            if ($img) {
                $canvas->insert($img, $x, $y);
            }

            // Price chip across the bottom of the card.
            $chipY = $y + $cardH - 32;
            $canvas->drawRectangle(fn (RectangleFactory $r) => $r
                ->at($x, $chipY)->size($cardW, 32)->background('rgba(8,10,14,0.82)'));
            $canvas->text($this->money((int) $card->value), $x + (int) ($cardW / 2), $chipY + 16, fn (FontFactory $f) => $f
                ->filename($this->font('Bold'))->size(20)->color(self::WHITE)->align('center', 'center'));
        }
    }

    private function footer(ImageInterface $canvas, int $count): void
    {
        $canvas->drawRectangle(fn (RectangleFactory $r) => $r
            ->at(44, 588)->size(self::W - 88, 2)->background(self::GOLD));
        $canvas->text("Top {$count} cards by value", 44, 600, fn (FontFactory $f) => $f
            ->filename($this->font('Regular'))->size(18)->color(self::MUTED)->align('left', 'top'));
        $canvas->text('Wax on.', self::W - 44, 598, fn (FontFactory $f) => $f
            ->filename($this->font('Bold'))->size(20)->color(self::GOLD)->align('right', 'top'));
    }

    private function fetch(ImageManager $manager, string $url, int $w, int $h): ?ImageInterface
    {
        try {
            $res = Http::timeout(30)->retry(2, 500, throw: false)->get($url);
            if (! $res->successful()) {
                return null;
            }

            return $manager->decodeBinary($res->body())->cover($w, $h);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function font(string $weight): string
    {
        return resource_path("fonts/NotoSans-{$weight}.ttf");
    }

    private function money(int $cents): string
    {
        $dollars = $cents / 100;

        return '$'.number_format($dollars, $dollars >= 100 ? 0 : 2);
    }

    private function clip(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
