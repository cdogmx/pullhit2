<?php

namespace App\Http\Controllers\Api;

use App\Actions\Billing\ApplySubscriptionWebhook;
use App\Http\Controllers\Controller;
use App\Support\Billing\DodoWebhookVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

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
}
