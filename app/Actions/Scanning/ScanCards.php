<?php

namespace App\Actions\Scanning;

use App\Http\Resources\CatalogItemResource;
use App\Models\ScanLog;
use App\Models\User;
use App\Support\Membership\ScanQuota;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\IdentifiedCard;
use App\Support\Scanning\IdentifierStrategy;
use App\Support\Scanning\ScanArchive;

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
    ) {}

    /**
     * @return array{detected: array<int, array<string, mixed>>, usage: array<string, mixed>}
     */
    public function __invoke(User $user, string $base64, string $mediaType, string $mode): array
    {
        $quota = ScanQuota::for($user);
        $quota->ensure();

        $cards = $mode === 'bulk'
            ? $this->strategy->identifyBulk($base64, $mediaType)
            : [$this->strategy->identifySingle($base64, $mediaType)];

        // Only cards that actually hit the vision API count against the AI quota;
        // ones recognised from the cache are free (no credit, no cost).
        $total = count($cards);
        $aiReads = count(array_filter($cards, fn (IdentifiedCard $c) => $c->source !== 'cache'));
        $creditsSpent = $aiReads > 0 ? $quota->record($aiReads) : 0;

        $detected = array_map(fn (IdentifiedCard $card) => $this->present($card), $cards);

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
            ]);
        }

        return ['detected' => $detected, 'usage' => $quota->snapshot()];
    }

    /** @return array<string, mixed> */
    protected function present(IdentifiedCard $card): array
    {
        $matches = $this->matcher->match($card);

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
                'language' => $card->language,
                'is_graded' => $card->isGraded,
                'grading_company' => $card->gradingCompany,
                'grade' => $card->grade,
                'edition' => $card->edition,
                'variant' => $card->variant,
                'confidence' => round($card->confidence, 2),
            ],
            'thumbnail' => $card->thumbnail,
            'fingerprint' => $card->phash,
            'source' => $card->source,
            'candidates' => $candidates,
        ];
    }
}
