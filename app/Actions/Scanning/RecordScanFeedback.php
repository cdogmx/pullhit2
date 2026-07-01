<?php

namespace App\Actions\Scanning;

use App\Actions\Community\AwardPoints;
use App\Enums\ContributionType;
use App\Models\ScanFeedback;
use App\Models\User;
use App\Support\Scanning\FingerprintCache;

/**
 * Record a "was the detection correct?" report and act on it:
 *  - wrong CACHE hit → demote/remove that fingerprint association (self-healing);
 *  - a supplied correction → teach the cache the right association;
 *  - right CACHE hit → reinforce it.
 * Vision misses are just recorded — they're the data for tuning the AI/matching.
 * Rating a detection earns a few points (once per fingerprint) — it improves the
 * scanner, so it's worth rewarding.
 */
class RecordScanFeedback
{
    public function __construct(
        protected FingerprintCache $cache,
        protected AwardPoints $award,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function __invoke(User $user, array $data): ScanFeedback
    {
        $source = (string) ($data['source'] ?? 'vision');
        $phash = $data['phash'] ?? null;
        $wasCorrect = (bool) ($data['was_correct'] ?? false);
        $detectedId = $data['detected_catalog_item_id'] ?? null;
        $correctedId = $data['corrected_catalog_item_id'] ?? null;

        $feedback = ScanFeedback::create([
            'user_id' => $user->id,
            'source' => $source,
            'phash' => $phash,
            'was_correct' => $wasCorrect,
            'identified' => $data['identified'] ?? null,
            'detected_catalog_item_id' => $detectedId,
            'corrected_catalog_item_id' => $correctedId,
        ]);

        if ($phash) {
            if (! $wasCorrect && $source === 'cache' && $detectedId) {
                // The cache matched the wrong card — weaken/purge that association.
                $this->cache->demote((string) $phash, (int) $detectedId);
            }

            if ($correctedId) {
                // Teach the right association so the next look-alike scan is a hit.
                $this->cache->record((string) $phash, (int) $correctedId, $user->id);
            } elseif ($wasCorrect && $source === 'cache' && $detectedId) {
                $this->cache->record((string) $phash, (int) $detectedId, $user->id);
            }

            // Reward the feedback — once per distinct fingerprint (no farming).
            ($this->award)(
                $user,
                ContributionType::ScanFeedback,
                description: 'Rated a scan detection',
                key: 'phash:'.$phash,
            );
        }

        return $feedback;
    }
}
