<?php

namespace App\Support\Scanning;

/**
 * TCG (Pokémon-first) scan identifier. Single mode = one vision call. Bulk mode =
 * detect-then-crop: locate each card, crop it server-side, then identify every
 * crop concurrently — so each card is read at full resolution.
 */
class PokemonIdentifierStrategy implements IdentifierStrategy
{
    public function __construct(
        protected AnthropicVisionClient $vision,
        protected ImageCropper $cropper,
    ) {}

    public function identifySingle(string $base64, string $mediaType): IdentifiedCard
    {
        return IdentifiedCard::fromVision($this->vision->identifyCard($base64, $mediaType));
    }

    /** @return array<int, IdentifiedCard> */
    public function identifyBulk(string $base64, string $mediaType): array
    {
        $boxes = $this->vision->detectCards($base64, $mediaType);
        if ($boxes === []) {
            return [];
        }

        $boxes = array_slice($boxes, 0, (int) config('scanning.bulk_max_cards', 20));
        $binary = base64_decode($base64);

        // Crop each detected card to its own full-resolution JPEG.
        $crops = [];
        foreach ($boxes as $box) {
            $jpeg = $this->cropper->crop($binary, $box);
            $crops[] = [
                'box' => $box,
                'b64' => base64_encode($jpeg),
                'thumbnail' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
            ];
        }

        // Identify all crops in one concurrent batch.
        $results = $this->vision->identifyMany(
            array_map(fn ($c) => ['base64' => $c['b64'], 'media_type' => 'image/jpeg'], $crops),
        );

        $cards = [];
        foreach ($crops as $i => $crop) {
            if (($input = $results[$i] ?? null) === null) {
                continue; // a crop that failed to identify is dropped
            }
            $cards[] = IdentifiedCard::fromVision($input, $crop['thumbnail'], $crop['box']);
        }

        return $cards;
    }
}
