<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\SearchCatalog;
use App\Actions\Scanning\RecordScanFeedback;
use App\Actions\Scanning\ScanCards;
use App\Exceptions\TooManyScansException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Scanning\ScanConfirmRequest;
use App\Http\Requests\Scanning\ScanRequest;
use App\Http\Resources\CatalogItemResource;
use App\Models\GradingCompany;
use App\Support\Membership\ScanQuota;
use App\Support\Scanning\FingerprintCache;
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

    /**
     * Learn a confirmed scan into the recognition cache so the same-looking card
     * can be matched without an AI read next time.
     */
    public function confirm(ScanConfirmRequest $request, FingerprintCache $cache): JsonResponse
    {
        $cache->record(
            (string) $request->input('fingerprint'),
            (int) $request->input('catalog_item_id'),
            $request->user()->id,
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Record whether a detection was correct. Self-heals the cache on a wrong
     * cache hit and learns the correction; vision misses are logged for tuning.
     */
    public function feedback(Request $request, RecordScanFeedback $record): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', 'in:cache,vision'],
            'was_correct' => ['required', 'boolean'],
            'phash' => ['nullable', 'string', 'max:32'],
            'identified' => ['nullable', 'array'],
            'detected_catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
            'corrected_catalog_item_id' => ['nullable', 'integer', 'exists:catalog_items,id'],
        ]);

        $record($request->user(), $data);

        return response()->json(['ok' => true]);
    }

    /**
     * Catalog search for the scan confirm grid, so a user can pick the right
     * card when the scan didn't surface it. Reuses the shared SearchCatalog.
     */
    public function search(Request $request, SearchCatalog $search): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = $search(['q' => $query, 'per_page' => 10]);

        return response()->json([
            'results' => CatalogItemResource::collection($results->items())->resolve(),
        ]);
    }
}
