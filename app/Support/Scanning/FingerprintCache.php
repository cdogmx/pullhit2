<?php

namespace App\Support\Scanning;

use App\Models\CatalogItem;
use App\Models\ScanFingerprint;

/**
 * The AI-free recognition layer. Given the perceptual hash of a freshly scanned
 * photo, find the catalog item a previous (confirmed) scan of the same-looking
 * card was matched to — and learn new associations as users confirm them.
 *
 * Lookup is a small in-PHP Hamming scan over the learned set (cheap while the
 * cache is modest); if it ever grows large enough to matter we can bucket by a
 * hash prefix. The point is to skip the vision API for cards seen before.
 */
class FingerprintCache
{
    /**
     * Best learned match for a hash, or null when nothing is close enough.
     *
     * @return array{item: CatalogItem, distance: int, confirmations: int}|null
     */
    public function lookup(string $phash): ?array
    {
        $maxDistance = (int) config('scanning.fingerprint_max_distance', 10);

        $best = null;
        $bestDistance = $maxDistance + 1;
        $bestConfirmations = 0;

        ScanFingerprint::query()
            ->with(['catalogItem.set', 'catalogItem.productLine', 'catalogItem.defaultMarketValue.gradingCompany'])
            ->orderByDesc('confirmations')
            ->lazy()
            ->each(function (ScanFingerprint $fp) use ($phash, &$best, &$bestDistance, &$bestConfirmations) {
                if ($fp->catalogItem === null) {
                    return;
                }

                $distance = PerceptualHash::distance($phash, $fp->phash);

                // Closest wins; ties break toward the more-confirmed association.
                if ($distance < $bestDistance
                    || ($distance === $bestDistance && $fp->confirmations > $bestConfirmations)) {
                    $best = $fp->catalogItem;
                    $bestDistance = $distance;
                    $bestConfirmations = $fp->confirmations;
                }
            });

        if ($best === null || $bestDistance > $maxDistance) {
            return null;
        }

        return ['item' => $best, 'distance' => $bestDistance, 'confirmations' => $bestConfirmations];
    }

    /**
     * Record that a scan with this fingerprint was confirmed to be the given
     * catalog item. Idempotent per (hash, item); repeats raise the confidence.
     */
    public function record(string $phash, int $catalogItemId, ?int $userId = null): ScanFingerprint
    {
        $fingerprint = ScanFingerprint::firstOrNew([
            'phash' => $phash,
            'catalog_item_id' => $catalogItemId,
        ]);

        $fingerprint->confirmations = ($fingerprint->confirmations ?? 0) + 1;
        $fingerprint->last_user_id = $userId;
        $fingerprint->save();

        return $fingerprint;
    }

    /**
     * Walk back a wrong cache hit: drop a confirmation from the (hash, item)
     * association and delete it once it has no confidence left. A single bad
     * report can't erase a well-confirmed entry, but an unproven one is purged.
     */
    public function demote(string $phash, int $catalogItemId): void
    {
        $fingerprint = ScanFingerprint::where('phash', $phash)
            ->where('catalog_item_id', $catalogItemId)
            ->first();

        if ($fingerprint === null) {
            return;
        }

        if ($fingerprint->confirmations <= 1) {
            $fingerprint->delete();

            return;
        }

        $fingerprint->decrement('confirmations');
    }
}
