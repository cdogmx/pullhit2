<?php

namespace App\Support\Grading;

/**
 * A normalised rectangle (0–1, origin top-left) — the same coordinate space the
 * scanner's vision client already returns bounding boxes in, so a card's outer
 * edge and its inner frame can be measured against each other without caring
 * about the source photo's pixel size.
 */
readonly class Rect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    /** @param  array<string, mixed>  $box */
    public static function fromArray(array $box): self
    {
        return new self(
            (float) ($box['x'] ?? 0),
            (float) ($box['y'] ?? 0),
            (float) ($box['width'] ?? 0),
            (float) ($box['height'] ?? 0),
        );
    }

    public function right(): float
    {
        return $this->x + $this->width;
    }

    public function bottom(): float
    {
        return $this->y + $this->height;
    }

    /** Is $inner wholly inside this rect? A frame that escapes the card is a bad read. */
    public function contains(self $inner): bool
    {
        return $inner->x >= $this->x
            && $inner->y >= $this->y
            && $inner->right() <= $this->right()
            && $inner->bottom() <= $this->bottom();
    }
}
