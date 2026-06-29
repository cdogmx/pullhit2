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
use App\Models\CatalogItem;
use App\Models\GradingCompany;
use App\Models\ScanLog;
use App\Support\Membership\ScanQuota;
use App\Support\Scanning\FingerprintCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
            // The user's existing folder names, for the add-to-collection picker.
            'folders' => $request->user()->collectionItems()
                ->whereNotNull('folder')->where('folder', '!=', '')
                ->distinct()->orderBy('folder')->pluck('folder')->all(),
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

    /** The signed-in user's scan history — the scanned photo + what was detected. */
    public function history(Request $request): Response
    {
        $paginator = $request->user()->scanLogs()
            ->whereNotNull('image_path')
            ->latest()
            ->paginate(20);

        // The snapshot stored only the matched card's id, so look up each card's
        // CURRENT headline value — the history reflects today's prices, not the
        // value at scan time. One batched query for the whole page.
        $matchIds = collect($paginator->items())
            ->flatMap(fn (ScanLog $log) => collect($log->results ?? [])->pluck('match.id'))
            ->filter()
            ->unique()
            ->all();

        $values = CatalogItem::with('defaultMarketValue')
            ->whereIn('id', $matchIds)
            ->get()
            ->mapWithKeys(fn (CatalogItem $item) => [$item->id => $item->defaultMarketValue?->median]);

        return Inertia::render('scan/history', [
            'scans' => collect($paginator->items())->map(fn (ScanLog $log) => $this->scanRow($log, $values)),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** Remove a scan from the user's history (owner only). */
    public function destroyScan(Request $request, ScanLog $scanLog): RedirectResponse
    {
        abort_unless($scanLog->user_id === $request->user()->id, 403);

        $scanLog->delete();

        return back()->with('success', 'Scan removed.');
    }

    /**
     * @param  Collection<int, int|null>  $values  matched id → current median (cents)
     * @return array<string, mixed>
     */
    private function scanRow(ScanLog $log, Collection $values): array
    {
        $results = collect($log->results ?? [])->map(function (array $r) use ($values) {
            $id = $r['match']['id'] ?? null;
            $r['value'] = $id ? $values->get($id) : null;

            return $r;
        });

        return [
            'id' => $log->id,
            'mode' => $log->mode,
            'image_url' => $log->image_path,
            'card_count' => (int) $log->cards,
            'ai_reads' => (int) $log->ai_reads,
            'cache_hits' => (int) $log->cache_hits,
            'results' => $results->all(),
            'total_value' => (int) $results->sum(fn (array $r) => $r['value'] ?? 0),
            'priced_count' => $results->filter(fn (array $r) => ($r['value'] ?? null) !== null)->count(),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
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
