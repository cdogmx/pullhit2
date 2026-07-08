<?php

namespace App\Actions\Valuation;

use App\Enums\ItemType;
use App\Jobs\RefreshPricechartingData;
use App\Models\CatalogItem;

/**
 * On a card view, lazily pull its PriceCharting data (completed sales + long-term
 * monthly history, per grade tier for singles) EXACTLY ONCE — if it's already
 * been synced, it's never re-pulled automatically (an admin "Refresh" forces a
 * fresh pull, and a batch sweep can re-warm en masse). Sealed products always
 * qualify; singles are gated to cards graded/long-term history is worth a scrape
 * for (see isEligible), so ~50k bulk commons don't drain the daily cap.
 * Fire-and-forget (queued).
 */
class MaybeRefreshPricecharting
{
    public function __invoke(CatalogItem $item): void
    {
        if (! config('valuation.pricecharting.enabled', true)
            || ! $this->isEligible($item)
            || ! $this->isDue($item)) {
            return;
        }

        RefreshPricechartingData::dispatch($item->id);
    }

    /**
     * Sealed products always resolve to a PriceCharting page and are few. Singles
     * are only worth a scrape above a value floor, and we skip low-rarity modern
     * cards — a vintage common/uncommon can still be valuable, so those bypass
     * the rarity skip.
     */
    public function isEligible(CatalogItem $item): bool
    {
        if ($item->item_type === ItemType::Sealed) {
            return true;
        }

        if ($item->item_type !== ItemType::Single) {
            return false;
        }

        $floor = (int) config('valuation.pricecharting.singles_min_cents', 500);
        if ((int) ($item->defaultMarketValue?->median ?? 0) < $floor) {
            return false;
        }

        return ! $this->isSkippedRarity($item) || $this->isVintage($item);
    }

    /** Whether the card's rarity is one we skip for singles (e.g. Common/Uncommon). */
    private function isSkippedRarity(CatalogItem $item): bool
    {
        $rarity = $item->getAttribute('attributes')['rarity'] ?? null;
        if ($rarity === null) {
            return false;
        }

        $skip = array_map('mb_strtolower', (array) config('valuation.pricecharting.skip_rarities', []));

        return in_array(mb_strtolower((string) $rarity), $skip, true);
    }

    /** A card from a set released before the vintage cutoff year. */
    private function isVintage(CatalogItem $item): bool
    {
        $released = $item->set?->released_at;
        $before = (int) config('valuation.pricecharting.vintage_before_year', 2004);

        return $released !== null && (int) $released->year < $before;
    }

    /** Due only if it has never been synced — already-pulled items are left alone. */
    public function isDue(CatalogItem $item): bool
    {
        return $item->pc_synced_at === null;
    }
}
