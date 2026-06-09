<?php

namespace App\Actions\Scanning;

use App\Http\Resources\CatalogItemResource;
use App\Models\User;
use App\Support\Membership\ScanQuota;
use App\Support\Scanning\CandidateMatcher;
use App\Support\Scanning\IdentifiedCard;
use App\Support\Scanning\IdentifierStrategy;

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

        $quota->record(count($cards));

        $detected = array_map(fn (IdentifiedCard $card) => $this->present($card), $cards);

        return ['detected' => $detected, 'usage' => $quota->snapshot()];
    }

    /** @return array<string, mixed> */
    protected function present(IdentifiedCard $card): array
    {
        $candidates = array_map(fn (array $c) => [
            'card' => (new CatalogItemResource($c['item']))->resolve(),
            'score' => $c['score'],
            'reasons' => $c['reasons'],
        ], $this->matcher->match($card));

        return [
            'identified' => [
                'name' => $card->name,
                'number' => $card->number,
                'set_name' => $card->setName,
                'language' => $card->language,
                'is_graded' => $card->isGraded,
                'grading_company' => $card->gradingCompany,
                'grade' => $card->grade,
                'confidence' => round($card->confidence, 2),
            ],
            'thumbnail' => $card->thumbnail,
            'candidates' => $candidates,
        ];
    }
}
