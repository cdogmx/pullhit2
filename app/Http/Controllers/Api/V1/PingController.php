<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Trivial liveness/auth probe for the versioned API.
 *
 * Establishes the App\Http\Controllers\Api\V1 namespace that later phases
 * (scan, catalog, marketplace) extend. Controllers stay thin — domain logic
 * belongs in app/Actions/ per the build brief.
 */
class PingController extends Controller
{
    /**
     * Public health check — no auth required.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'api',
            'version' => 'v1',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * Token-protected probe — proves Sanctum bearer-token auth works.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'authenticated' => true,
            'user' => $request->user(),
        ]);
    }
}
