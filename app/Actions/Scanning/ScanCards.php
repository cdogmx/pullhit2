<?php

namespace App\Actions\Scanning;

use App\Actions\Catalog\CreateProvisionalCard;
use App\Actions\Community\AwardPoints;
use App\Enums\ContributionType;
use App\Http\Resources\CatalogItemResource;
use App\Models\CatalogItem;
use App\Models\ScanLog;
use App\Models\User;
use App\Support\Membership\ScanQuota;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\IdentifiedCard;
use App\Support\Scanning\IdentifierStrategy;
use App\Support\Scanning\ScanArchive;
use App\Support\Scanning\ScanTimer;

/**
 * Orchestrates a scan: enforce the user's monthly quota, identify the card(s) via
 * the vertical strategy, match each against the catalog, record usage (by cards
 * identified), and return a confirm-ready payload. No DB writes to the collection
 * — the user confirms, then the existing AddToCollection flow adds the card.
 */
class ScanCards
{
    public function __construct(
        protected IdentifierStrategy $strategy,
        protected CandidateMatcher $matcher,
        protected ScanArchive $archive,
        protected AwardPoints $award,
        protected ScanTimer $timer,
        protected CreateProvisionalCard $provisional,
    ) {}

    /**
     * @return array{detected: array<int, array<string, mixed>>, usage: array<string, mixed>}
     */
    public function __invoke(User $user, string $base64, string $mediaType, string $mode): array
    {
        $quota = ScanQuota::for($user);
        $quota->ensure();

        // Timed from here rather than from the request, so the number measures
        // the scan and not the upload sitting in front of it. Reset first: the
        // timer is request-scoped, but a caller that scans twice in one request
        // would otherwise report the first scan's time again.
        $this->timer->reset();
        $startedAt = hrtime(true);

        $cards = $mode === 'bulk'
            ? $this->strategy->identifyBulk($base64, $mediaType)
            : [$this->strategy->identifySingle($base64, $mediaType)];

        // Only cards that actually hit the vision API count against the AI quota;
        // ones recognised from the cache are free (no credit, no cost).
        $total = count($cards);
        $aiReads = count(array_filter($cards, fn (IdentifiedCard $c) => $c->source !== 'cache'));
        $creditsSpent = $aiReads > 0 ? $quota->record($aiReads) : 0;

        $detected = array_map(fn (IdentifiedCard $card) => $this->present($card, $user), $cards);

        // Record the scan for the user's history (thumbnail + results snapshot).
        if ($total > 0) {
            $archived = $this->archive->build($user, $base64, $detected);

            ScanLog::create([
                'user_id' => $user->id,
                'mode' => $mode,
                'image_path' => $archived['image_path'],
                'results' => $archived['results'],
                'cards' => $total,
                'ai_reads' => $aiReads,
                'cache_hits' => $total - $aiReads,
                'credits_spent' => $creditsSpent,

                // Where the time went. The phases are nullable because not every
                // scan runs all of them: single mode never detects, and a scan
                // answered entirely from cache never identifies — recording a
                // zero there would read as "instant" rather than "did not run".
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'detect_ms' => $this->timer->ms('detect'),
                'identify_ms' => $this->timer->ms('identify'),
                'fingerprint_ms' => $this->timer->ms('fingerprint'),
                'match_ms' => $this->timer->ms('match'),
                // Decoded size of the upload, from the base64 length — close
                // enough without decoding a multi-megabyte photo again.
                'image_bytes' => intdiv(strlen($base64) * 3, 4),
            ]);

            // One-time "first scan" milestone (once ever per user).
            ($this->award)($user, ContributionType::FirstScan, description: 'Ran your first scan');
        }

        return ['detected' => $detected, 'usage' => $quota->snapshot()];
    }

    /** @return array<string, mixed> */
    protected function present(IdentifiedCard $card, ?User $user = null): array
    {
        $matches = $this->timer->time('match', fn () => $this->matcher->match($card));

        // A cache-recognised card already knows its exact item — pin it to the top
        // (the matcher's name/number ranking still supplies alternatives).
        if ($card->matchedItem !== null) {
            $matches = array_values(array_filter(
                $matches,
                fn (array $m) => $m['item']->id !== $card->matchedItem->id,
            ));
            array_unshift($matches, [
                'item' => $card->matchedItem,
                'score' => 1.0,
                'reasons' => ['recognized'],
            ]);
            $matches = array_slice($matches, 0, (int) config('scanning.max_candidates', 5));
        }

        // Nothing in the catalog looks like this card. Rather than a dead end,
        // create the row provisionally so its finder can log it — quarantined
        // from browse, pricing and the sitemap until somebody confirms it.
        $created = null;

        if ($this->shouldCreateProvisional($card, $matches, $user)) {
            $created = ($this->provisional)($card, $user);

            if ($created !== null) {
                // Offered as the match, so the confirm step behaves exactly as it
                // does for a card we already held.
                array_unshift($matches, [
                    'item' => $created,
                    'score' => 1.0,
                    'reasons' => ['added from your scan'],
                ]);
            }
        }

        $candidates = array_map(fn (array $c) => [
            'card' => (new CatalogItemResource($c['item']))->resolve(),
            'score' => $c['score'],
            'reasons' => $c['reasons'],
        ], $matches);

        return [
            'identified' => [
                'name' => $card->name,
                'number' => $card->number,
                'set_name' => $card->setName,
                'set_code' => $card->setCode,
                'language' => $card->language,
                'is_graded' => $card->isGraded,
                'grading_company' => $card->gradingCompany,
                'grade' => $card->grade,
                'edition' => $card->edition,
                'variant' => $card->variant,
                'confidence' => round($card->confidence, 2),
            ],
            'thumbnail' => $card->thumbnail,
            // Set when this scan added the card itself, so the UI can say so
            // rather than presenting a new row as an ordinary match.
            'added_provisionally' => $created !== null,
            'fingerprint' => $card->phash,
            'source' => $card->source,
            'candidates' => $candidates,
        ];
    }

    /**
     * Is this a card we genuinely do not hold, and is the read good enough to
     * build a row from?
     *
     * Both have to be true. A confident read that ALMOST matched something is far
     * more likely to be a card we hold under a slightly different name than a new
     * card — and creating a near-duplicate is the expensive mistake here, because
     * the name feeds the identity hash and the official import will never match
     * it. A hesitant read is refused for the same reason.
     *
     * @param  array<int, array{item: CatalogItem, score: float, reasons: array<int, string>}>  $matches
     */
    protected function shouldCreateProvisional(IdentifiedCard $card, array $matches, ?User $user): bool
    {
        $config = (array) config('scanning.provisional');

        if (! ($config['enabled'] ?? false) || $user === null) {
            return false;
        }

        // A cache hit already knows its exact card; there is nothing to create.
        if ($card->matchedItem !== null) {
            return false;
        }

        $best = $matches[0]['score'] ?? 0.0;

        if ($best >= (float) ($config['match_floor'] ?? 0.45)) {
            return false;
        }

        if ($card->confidence < (float) ($config['read_floor'] ?? 0.75)) {
            return false;
        }

        // A scanner pointed at something that is not a card should cost a handful
        // of rows, not a catalog.
        $cap = (int) ($config['daily_per_user'] ?? 25);

        return $cap <= 0 || CatalogItem::where('provisional_by', $user->id)
            ->where('provisional_at', '>=', now()->subDay())
            ->count() < $cap;
    }
}
