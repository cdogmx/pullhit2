<?php

namespace App\Support\Scanning;

/**
 * TCG (Pokémon-first) scan identifier. Single mode = one vision call. Bulk mode =
 * detect-then-crop: locate each card, crop it server-side, then identify every
 * crop concurrently — so each card is read at full resolution.
 *
 * Before any vision call, each card image is matched against the recognition
 * cache (a perceptual hash of previously-confirmed scans). A confident cache hit
 * is returned directly, skipping the AI entirely for cards seen before.
 */
class PokemonIdentifierStrategy implements IdentifierStrategy
{
    public function __construct(
        protected AnthropicVisionClient $vision,
        protected ImageCropper $cropper,
        protected FingerprintCache $cache,
        protected ScanTimer $timer,
    ) {}

    public function identifySingle(string $base64, string $mediaType): IdentifiedCard
    {
        $hit = $this->timer->time('fingerprint', function () use ($base64) {
            $phash = PerceptualHash::fromBinary(base64_decode($base64));

            return [$phash, $phash !== null ? $this->cache->lookup($phash) : null];
        });

        [$phash, $cached] = $hit;

        if ($cached !== null) {
            return IdentifiedCard::fromCache($cached['item'], $phash);
        }

        return IdentifiedCard::fromVision(
            $this->timer->time('identify', fn () => $this->vision->identifyCard($base64, $mediaType)),
            phash: $phash,
        );
    }

    /** @return array<int, IdentifiedCard> */
    public function identifyBulk(string $base64, string $mediaType): array
    {
        $boxes = $this->timer->time('detect', fn () => $this->vision->detectCards($base64, $mediaType));
        if ($boxes === []) {
            return [];
        }

        $boxes = array_slice($boxes, 0, (int) config('scanning.bulk_max_cards', 20));
        $binary = base64_decode($base64);
        $pad = (float) config('scanning.bulk_crop_padding', 0.08);

        // Crop each detected card to its own full-resolution JPEG, fingerprint it,
        // and check the recognition cache so seen-before cards skip the AI read.
        $crops = [];
        foreach ($boxes as $box) {
            $crops[] = $this->timer->time('fingerprint', function () use ($binary, $box, $pad) {
                $jpeg = $this->cropper->crop($binary, $box, $pad);
                $phash = PerceptualHash::fromBinary($jpeg);

                return [
                    'box' => $box,
                    'b64' => base64_encode($jpeg),
                    'thumbnail' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
                    'phash' => $phash,
                    'cached' => $phash !== null ? $this->cache->lookup($phash) : null,
                ];
            });
        }

        // Only crops without a cache hit need a vision call. Keep their original
        // index so the AI results land back on the right crop.
        $toIdentify = [];
        foreach ($crops as $i => $crop) {
            if ($crop['cached'] === null) {
                $toIdentify[$i] = ['base64' => $crop['b64'], 'media_type' => 'image/jpeg'];
            }
        }

        $results = $toIdentify === []
            ? []
            : $this->timer->time('identify', fn () => $this->vision->identifyMany(array_values($toIdentify)));

        // Map the compacted result list back onto the original crop indexes.
        $byIndex = [];
        foreach (array_keys($toIdentify) as $position => $cropIndex) {
            $byIndex[$cropIndex] = $results[$position] ?? null;
        }

        $cards = [];
        foreach ($crops as $i => $crop) {
            if ($crop['cached'] !== null) {
                $cards[] = IdentifiedCard::fromCache(
                    $crop['cached']['item'], $crop['phash'], $crop['thumbnail'], $crop['box'],
                );

                continue;
            }

            if (($input = $byIndex[$i] ?? null) === null) {
                continue; // a crop that failed to identify is dropped
            }

            $cards[] = IdentifiedCard::fromVision($input, $crop['thumbnail'], $crop['box'], $crop['phash']);
        }

        return $cards;
    }
}
