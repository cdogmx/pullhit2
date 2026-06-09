<?php

namespace App\Http\Controllers\Web;

use App\Actions\Scanning\ScanCards;
use App\Exceptions\TooManyScansException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scanning\ScanRequest;
use App\Models\GradingCompany;
use App\Support\Membership\ScanQuota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Card scanner (Inertia). The page captures/uploads a photo and POSTs it to
 * `scan`, which returns JSON candidates the user confirms before adding to their
 * collection (via the existing /collection flow). Thin — delegates to ScanCards.
 */
class ScanController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('scan/index', [
            'usage' => ScanQuota::for($request->user())->snapshot(),
            'gradingCompanies' => GradingCompany::orderBy('name')
                ->get(['id', 'slug', 'name', 'scale_max', 'supports_half_grades']),
        ]);
    }

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
