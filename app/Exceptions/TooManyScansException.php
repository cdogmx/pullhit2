<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a non-admin user has exhausted their monthly scan allowance.
 * Controllers translate this into a 429 with the current usage payload.
 */
class TooManyScansException extends RuntimeException
{
    public function __construct(public readonly int $cap)
    {
        parent::__construct("Monthly scan limit of {$cap} reached.");
    }
}
