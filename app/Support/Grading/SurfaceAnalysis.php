<?php

namespace App\Support\Grading;

/**
 * The result of reading a tilt sequence for surface damage: the defects found,
 * a coarse severity bucket, and the 0–1000 score that bucket feeds into the
 * weakest-link roll-up.
 *
 * The score is deliberately coarse. Without known light directions we recover
 * relative variation, not calibrated surface normals, so this can say "several
 * scratches, moderate" — it cannot say 867. Treat it as a bucket that happens
 * to carry a number, not a measurement.
 */
readonly class SurfaceAnalysis
{
    /** @param  array<int, SurfaceDefect>  $defects */
    public function __construct(
        public array $defects,
        public int $score,
        public string $bucket,
        public int $framesUsed,
        public float $specularRange,
    ) {}

    /**
     * Did the capture actually carry surface information? If the highlight never
     * moved, every frame is the same photo and there is nothing to difference —
     * we must say "couldn't see it" rather than "looks clean".
     */
    public function isUsable(): bool
    {
        return $this->framesUsed >= 2 && $this->specularRange >= 8.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'defects' => array_map(fn (SurfaceDefect $d) => $d->toArray(), $this->defects),
            'defect_count' => count($this->defects),
            'score' => $this->score,
            'bucket' => $this->bucket,
            'frames_used' => $this->framesUsed,
            'specular_range' => round($this->specularRange, 1),
            'usable' => $this->isUsable(),
        ];
    }
}
