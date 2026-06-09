<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scanning\ScanCards;
use App\Exceptions\TooManyScansException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scanning\ScanRequest;
use App\Support\Membership\ScanQuota;
use Illuminate\Http\JsonResponse;

/**
 * Card scanner for API clients (native app). Same ScanCards action as the web
 * controller (§2); auth via Sanctum token.
 */
class ScanController extends Controller
{
    public function scan(ScanRequest $request, ScanCards $scan): JsonResponse
    {
        try {
            $result = $scan(
                $request->user(),
                (string) $request->input('image'),
                (string) $request->input('media_type'),
                (string) $request->input('mode'),
            );
        } catch (TooManyScansException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'usage' => ScanQuota::for($request->user())->snapshot(),
            ], 429);
        }

        return response()->json($result);
    }
}
