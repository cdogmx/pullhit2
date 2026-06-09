<?php

namespace App\Support\Scanning;

/**
 * Per-vertical scan identification (§3 IdentifierStrategy seam). Turns a photo
 * into one or more IdentifiedCards; catalog matching happens separately.
 */
interface IdentifierStrategy
{
    public function identifySingle(string $base64, string $mediaType): IdentifiedCard;

    /** @return array<int, IdentifiedCard> */
    public function identifyBulk(string $base64, string $mediaType): array;
}
