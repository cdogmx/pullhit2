<?php

namespace App\Support\Billing;

/**
 * Verifies Dodo Payments webhooks (Standard Webhooks spec): HMAC-SHA256 over
 * "{id}.{timestamp}.{raw-body}" with the base64 `whsec_…` secret, compared
 * constant-time against the v1 signatures in the webhook-signature header.
 */
class DodoWebhookVerifier
{
    /** Tolerance for the webhook timestamp (seconds). */
    private const TOLERANCE = 300;

    public function verify(string $rawBody, string $id, string $timestamp, string $signatureHeader): bool
    {
        $secret = (string) config('services.dodo.webhook_secret');

        if ($secret === '' || $id === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE) {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$rawBody}", $this->key($secret), true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) as $part) {
            $sig = str_contains($part, ',') ? explode(',', $part, 2)[1] : $part;
            if ($sig !== '' && hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    /** The signing key — Standard Webhooks base64-decodes the `whsec_`-prefixed secret. */
    protected function key(string $secret): string
    {
        $b64 = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($b64, true);

        return $decoded !== false ? $decoded : $secret;
    }
}
