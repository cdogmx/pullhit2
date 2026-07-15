<?php

namespace App\Support\Scanning;

use App\Models\CatalogItem;

/**
 * Fields a vision pass extracted from one card image, before catalog matching.
 * `thumbnail` (a data: URI of the crop) and `box` are only set for bulk scans.
 *
 * `phash` is the perceptual hash of the scanned image (so a confirmed add can be
 * learned into the recognition cache). When a card is recognised from that cache
 * instead of the AI, `source` is 'cache' and `matchedItem` is the known item.
 */
readonly class IdentifiedCard
{
    public function __construct(
        public ?string $name,
        public ?string $number,
        public ?string $setName,
        public ?string $language,
        public ?string $setCode = null,
        public bool $isGraded = false,
        public ?string $gradingCompany = null,
        public ?float $grade = null,
        public float $confidence = 0.0,
        public ?string $thumbnail = null,
        public ?array $box = null,
        public ?string $edition = null,   // first_edition | shadowless | unlimited | null
        public ?string $variant = null,   // reverse_holo | holo | normal | null
        public ?string $phash = null,
        public ?CatalogItem $matchedItem = null,
        public string $source = 'vision',  // vision | cache
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromVision(array $input, ?string $thumbnail = null, ?array $box = null, ?string $phash = null): self
    {
        return new self(
            name: $input['name'] ?? null,
            number: $input['number'] ?? null,
            setName: $input['set_name'] ?? null,
            language: $input['language'] ?? null,
            setCode: $input['set_code'] ?? null,
            isGraded: (bool) ($input['is_graded'] ?? false),
            gradingCompany: $input['grading_company'] ?? null,
            grade: isset($input['grade']) ? (float) $input['grade'] : null,
            confidence: (float) ($input['confidence'] ?? 0.0),
            thumbnail: $thumbnail,
            box: $box,
            edition: $input['edition'] ?? null,
            variant: $input['variant'] ?? null,
            phash: $phash,
        );
    }

    /**
     * Build from the recognition cache — the catalog item is already known, so
     * no AI read happened. Confidence is 1.0; the user still confirms the add.
     */
    public static function fromCache(CatalogItem $item, string $phash, ?string $thumbnail = null, ?array $box = null): self
    {
        $attributes = $item->getAttribute('attributes') ?? [];

        return new self(
            name: $item->name,
            number: $item->number,
            setName: $item->set?->name,
            language: $item->language,
            setCode: $item->set?->code,
            confidence: 1.0,
            thumbnail: $thumbnail,
            box: $box,
            edition: $attributes['edition'] ?? null,
            variant: $attributes['variant'] ?? null,
            phash: $phash,
            matchedItem: $item,
            source: 'cache',
        );
    }
}
