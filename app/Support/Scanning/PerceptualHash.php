<?php

namespace App\Support\Scanning;

/**
 * A 64-bit difference hash (dHash) of an image, as 16 hex chars. dHash is cheap
 * (no ML), stable across resize/recompression, and tolerant of small lighting
 * shifts — good for recognising "the same card photographed again." It is NOT a
 * substitute for OCR on near-identical printings (1st Ed vs Unlimited differ by
 * a tiny stamp), so it gates a confirm step rather than auto-adding.
 */
class PerceptualHash
{
    private const W = 9;  // one extra column: we compare each pixel to its right neighbour

    private const H = 8;

    /** Compute the dHash of raw image bytes, or null if the bytes aren't a decodable image. */
    public static function fromBinary(string $binary): ?string
    {
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return null;
        }

        $small = imagecreatetruecolor(self::W, self::H);
        imagecopyresampled($small, $src, 0, 0, 0, 0, self::W, self::H, imagesx($src), imagesy($src));
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        $bits = '';
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W - 1; $x++) {
                $left = imagecolorat($small, $x, $y) & 0xFF;
                $right = imagecolorat($small, $x + 1, $y) & 0xFF;
                $bits .= $left < $right ? '1' : '0';
            }
        }

        imagedestroy($src);
        imagedestroy($small);

        return self::bitsToHex($bits);
    }

    /** Hamming distance (0–64) between two hex hashes; higher = less alike. */
    public static function distance(string $a, string $b): int
    {
        $bitsA = self::hexToBits($a);
        $bitsB = self::hexToBits($b);
        $len = min(strlen($bitsA), strlen($bitsB));

        $distance = abs(strlen($bitsA) - strlen($bitsB));
        for ($i = 0; $i < $len; $i++) {
            if ($bitsA[$i] !== $bitsB[$i]) {
                $distance++;
            }
        }

        return $distance;
    }

    private static function bitsToHex(string $bits): string
    {
        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= base_convert(str_pad($nibble, 4, '0'), 2, 16);
        }

        return $hex;
    }

    private static function hexToBits(string $hex): string
    {
        $bits = '';
        foreach (str_split($hex) as $char) {
            $bits .= str_pad(base_convert($char, 16, 2), 4, '0', STR_PAD_LEFT);
        }

        return $bits;
    }
}
