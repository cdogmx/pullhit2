<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\ApplySubscriptionWebhook;
use App\Http\Controllers\Controller;
use App\Support\Billing\DodoWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Inbound provider webhooks. Stateless (api group → no CSRF/session); the
 * signature is the auth. Returns 2xx once verified so the provider stops retrying.
 */
class WebhookController extends Controller
{
    public function dodo(Request $request, DodoWebhookVerifier $verifier, ApplySubscriptionWebhook $apply): JsonResponse
    {
        $raw = $request->getContent();
        $id = (string) $request->header('webhook-id', '');

        if (! $verifier->verify(
            $raw,
            $id,
            (string) $request->header('webhook-timestamp', ''),
            (string) $request->header('webhook-signature', ''),
        )) {
            return response()->json(['message' => 'invalid signature'], 401);
        }

        // Idempotency — ignore a webhook-id we've already processed.
        if (! Cache::add("dodo:webhook:{$id}", true, Carbon::now()->addDay())) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        $apply((array) json_decode($raw, true));

        return response()->json(['ok' => true]);
    }

    /**
     * eBay Marketplace Account Deletion — challenge verification.
     *
     * eBay GETs this URL with ?challenge_code=… when you save the endpoint in
     * the developer portal. Respond with SHA-256(challengeCode + token + endpoint)
     * as hex in { "challengeResponse": "…" }. The endpoint string must match the
     * registered URL exactly (scheme, host, path; no trailing slash).
     *
     * @see https://developer.ebay.com/develop/guides-v2/marketplace-user-account-deletion
     */
    public function ebayMarketplaceDeletionChallenge(Request $request): JsonResponse
    {
        $challenge = (string) $request->query('challenge_code', '');
        $token = (string) config('services.ebay.marketplace_deletion_token', '');
        $endpoint = rtrim((string) config('services.ebay.marketplace_deletion_endpoint', ''), '/');

        if ($challenge === '' || $token === '' || $endpoint === '') {
            return response()->json(['message' => 'not configured'], 503);
        }

        $hash = hash('sha256', $challenge.$token.$endpoint);

        return response()->json(['challengeResponse' => $hash]);
    }

    /**
     * eBay Marketplace Account Deletion — notification delivery.
     *
     * Fired when an eBay user requests account/data deletion. We don't store
     * eBay user OAuth tokens today (app-level Browse + affiliate only), so we
     * acknowledge + log. If user-linked eBay data is added later, purge it here.
     */
    public function ebayMarketplaceDeletionNotify(Request $request): JsonResponse
    {
        $payload = $request->all();
        $notification = $payload['notification'] ?? $payload;
        $userData = $notification['data'] ?? [];

        $ebayUserId = $userData['userId']
            ?? $userData['username']
            ?? $notification['notificationId']
            ?? null;

        Log::info('ebay.marketplace_account_deletion', [
            'ebay_user' => $ebayUserId,
            'topic' => $payload['metadata']['topic'] ?? $notification['notificationId'] ?? null,
            'received_at' => now()->toIso8601String(),
        ]);

        // Always 200 so eBay stops retrying — we have nothing user-scoped to delete
        // under the current architecture (no eBay user tokens / profiles stored).
        return response()->json(['ok' => true]);
    }
}
