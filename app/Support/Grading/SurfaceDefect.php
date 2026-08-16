<?php

namespace App\Support\Grading;

/**
 * One candidate surface defect recovered from a tilt sequence — roughly the row
 * TAG's SURFACE DETAILS table holds ("Scratch(es)", a location, a region), minus
 * the calibrated geometry we have no way to measure without their rig.
 */
readonly class SurfaceDefect
{
    public function __construct(
        public float $x,
        public float $y,
        public float $length,
        public float $elongation,
        public float $strength,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'x' => round($this->x, 1),
            'y' => round($this->y, 1),
            'length' => round($this->length, 1),
            'elongation' => round($this->elongation, 2),
            'strength' => round($this->strength, 1),
        ];
    }
}
