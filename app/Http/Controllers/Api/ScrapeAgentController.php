<?php

namespace App\Http\Controllers\Api;

use App\Actions\Valuation\IngestEbaySoldComps;
use App\Http\Controllers\Controller;
use App\Models\EbayScrapeJob;
use App\Support\Ebay\EbayHtmlParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The server half of the browser agent: hand out sold searches, take back HTML.
 *
 * eBay serves completed listings only to a signed-in session, so the fetch has
 * to happen in a real logged-in browser. The agent is therefore an untrusted,
 * intermittent worker — it can disappear between claiming a job and reporting on
 * it — so work is leased with an expiry rather than assigned, and a lease that
 * runs out simply returns the job to the queue.
 *
 * The agent sends raw HTML and nothing else. Parsing, classification and
 * valuation stay on the server, where they already live and are already tested:
 * the extension is a transport, and is never trusted to say what a card sold for.
 */
class ScrapeAgentController extends Controller
{
    /** How long a claimed job is held before it returns to the queue. */
    private const LEASE_MINUTES = 10;

    /** Give up on a job that has been tried this many times. */
    private const MAX_ATTEMPTS = 3;

    /** Claim a batch of searches to run. */
    public function claim(Request $request): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
            'agent' => ['nullable', 'string', 'max:64'],
        ]);

        $limit = (int) ($data['limit'] ?? 5);
        $agent = Str::limit((string) ($data['agent'] ?? 'agent'), 60, '');
        $leasedUntil = now()->addMinutes(self::LEASE_MINUTES);

        // Claim inside a transaction with the rows locked, so two browsers (or
        // one browser polling twice) cannot take the same job.
        $jobs = DB::transaction(function () use ($limit, $agent, $leasedUntil) {
            $ids = EbayScrapeJob::claimable()
                ->orderByDesc('priority')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return collect();
            }

            EbayScrapeJob::whereIn('id', $ids)->update([
                'status' => EbayScrapeJob::STATUS_LEASED,
                'leased_until' => $leasedUntil,
                'leased_by' => $agent,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

            // Re-selected by id, so the ordering chosen above has to be applied
            // again — otherwise the agent works in id order and priority does
            // nothing, which is the whole point of having a queue.
            return EbayScrapeJob::with('catalogItem:id,name,number')
                ->whereIn('id', $ids)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->get();
        });

        return response()->json([
            'lease_seconds' => self::LEASE_MINUTES * 60,
            'jobs' => $jobs->map(fn (EbayScrapeJob $job) => [
                'id' => $job->id,
                'url' => $job->url,
                // Only so the popup can say what it is working on.
                'label' => trim($job->catalogItem?->name.' '.$job->catalogItem?->number),
            ])->values(),
        ]);
    }

    /**
     * Report on one job. Either HTML we should parse, or a reason it could not
     * be fetched.
     */
    public function result(Request $request, IngestEbaySoldComps $ingest): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            // ~2.5 MB of eBay search HTML is normal; the cap is a sanity bound.
            'html' => ['nullable', 'string', 'max:8000000'],
            'status' => ['nullable', 'in:blocked,failed'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $job = EbayScrapeJob::with('catalogItem')->findOrFail($data['id']);

        if ($job->status === EbayScrapeJob::STATUS_DONE) {
            return response()->json(['status' => 'already done', 'comps' => $job->comps_found]);
        }

        // The agent could not get the page. Do NOT touch ebay_refreshed_at —
        // "we were refused" must never be stored as "this card has no comps".
        if (($data['status'] ?? null) !== null || $data['html'] === null) {
            return response()->json($this->giveUpOrRequeue($job, $data['status'] ?? 'failed', $data['note'] ?? null));
        }

        $html = (string) $data['html'];

        // A sign-in wall is a successful HTTP fetch of the wrong page. Treated as
        // a real result it would wipe a card's comps, so it is caught here rather
        // than trusted to the parser returning nothing.
        if ($this->isRefusal($html)) {
            return response()->json($this->giveUpOrRequeue($job, EbayScrapeJob::STATUS_BLOCKED, 'sign-in wall or captcha'));
        }

        $candidates = EbayHtmlParser::parse($html);

        // Nothing parsed and eBay did not say "no matches" — the same ambiguous
        // page the server-side fetcher retries rather than believes.
        if ($candidates === [] && ! EbayHtmlParser::isEmptyResults($html)) {
            return response()->json($this->giveUpOrRequeue($job, EbayScrapeJob::STATUS_BLOCKED, 'no cards and no "no matches"'));
        }

        $comps = $ingest->ingest($job->catalogItem, $candidates);

        $job->forceFill([
            'status' => EbayScrapeJob::STATUS_DONE,
            'comps_found' => $comps,
            'note' => $candidates === [] ? 'eBay reported no matches' : null,
            'completed_at' => now(),
            'leased_until' => null,
        ])->save();

        return response()->json(['status' => 'ok', 'comps' => $comps, 'candidates' => count($candidates)]);
    }

    /** Queue depth and recent throughput — what the popup shows. */
    public function status(): JsonResponse
    {
        $counts = EbayScrapeJob::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'outstanding' => EbayScrapeJob::outstanding()->count(),
            'by_status' => $counts,
            'done_today' => EbayScrapeJob::where('status', EbayScrapeJob::STATUS_DONE)
                ->where('completed_at', '>=', now()->startOfDay())->count(),
            'comps_today' => (int) EbayScrapeJob::where('completed_at', '>=', now()->startOfDay())->sum('comps_found'),
            'blocked_today' => EbayScrapeJob::where('status', EbayScrapeJob::STATUS_BLOCKED)
                ->where('updated_at', '>=', now()->startOfDay())->count(),
        ]);
    }

    /**
     * eBay answered, but with a wall. Recognised by the page it actually is —
     * not by scanning for "signin.ebay.com", which appears in the nav of every
     * ordinary eBay page and once caused exactly this call to be made wrongly.
     */
    private function isRefusal(string $html): bool
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = trim($m[1]);

            foreach (['Security Measure', 'Sign in or Register', 'Error Page'] as $wall) {
                if (str_contains($title, $wall)) {
                    return true;
                }
            }
        }

        return str_contains($html, 'splashui/captcha');
    }

    /**
     * Put a failed job back for another go, or retire it once it has had enough.
     *
     * @return array<string, mixed>
     */
    private function giveUpOrRequeue(EbayScrapeJob $job, string $status, ?string $note): array
    {
        $exhausted = $job->attempts >= self::MAX_ATTEMPTS;

        $job->forceFill([
            'status' => $exhausted ? $status : EbayScrapeJob::STATUS_PENDING,
            'note' => $note,
            'leased_until' => null,
            'leased_by' => null,
            'completed_at' => $exhausted ? now() : null,
        ])->save();

        return [
            'status' => $exhausted ? $status : 'requeued',
            'attempts' => $job->attempts,
            // The agent uses this to stop hammering a wall it cannot get past.
            'should_pause' => $status === EbayScrapeJob::STATUS_BLOCKED,
        ];
    }
}
