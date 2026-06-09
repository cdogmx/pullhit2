<?php

namespace App\Support\Scanning;

/**
 * Fields a vision pass extracted from one card image, before catalog matching.
 * `thumbnail` (a data: URI of the crop) and `box` are only set for bulk scans.
 */
readonly class IdentifiedCard
{
    public function __construct(
        public ?string $name,
        public ?string $number,
        public ?string $setName,
        public ?string $language,
        public bool $isGraded = false,
        public ?string $gradingCompany = null,
        public ?float $grade = null,
        public float $confidence = 0.0,
        public ?string $thumbnail = null,
        public ?array $box = null,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromVision(array $input, ?string $thumbnail = null, ?array $box = null): self
    {
        return new self(
            name: $input['name'] ?? null,
            number: $input['number'] ?? null,
            setName: $input['set_name'] ?? null,
            language: $input['language'] ?? null,
            isGraded: (bool) ($input['is_graded'] ?? false),
            gradingCompany: $input['grading_company'] ?? null,
            grade: isset($input['grade']) ? (float) $input['grade'] : null,
            confidence: (float) ($input['confidence'] ?? 0.0),
            thumbnail: $thumbnail,
            box: $box,
        );
    }
}
