<?php

namespace App\Support\Valuation;

use App\Models\CatalogItem;
use App\Models\PricechartingProduct;
use Illuminate\Support\Str;

/**
 * The raw price a comp's plausibility is judged against.
 *
 * The classifier accepts an ungraded comp only if it lands within 0.1–5x of
 * this number, so the anchor decides which raw sales are believable. It used to
 * be the card's OWN raw median, which made the check circular: once a card's
 * raw value was wrong, the band it defended widened in proportion and waved the
 * next wrong listing through. Celebrations Flying Pikachu V held $53.35 against
 * a real ungraded market of $4.28, which lifted its ceiling from $21 to $267 —
 * and a $100 listing walked in and kept it there.
 *
 * PriceCharting's ungraded price breaks the loop because nothing we compute can
 * move it. Where we have no PriceCharting row — most of the catalog — this
 * falls back to the old behaviour, so those cards are no worse off.
 *
 * Note what is NOT fixed here: with no anchor at all the band still accepts
 * anything, because a card with no value yet has to be able to get one. That is
 * how a fresh card's first comp becomes its median unchallenged.
 */
class RawAnchor
{
    /**
     * @var array<int, array<int, array{num: string, name: string, cents: int}>>
     *   set id => its PriceCharting singles
     */
    private array $bySet = [];

    /**
     * Cents to measure raw comps against, or 0 when we have no opinion.
     *
     * Deliberately not memoized per card. The fallback is the card's own
     * median, which moves as comps are ingested, and callers that DO want a
     * fixed anchor for the length of a run — the sweep, while it defers
     * recomputes — hold that decision themselves.
     */
    public function for(CatalogItem $item): int
    {
        return $this->reference($item) ?? $this->fromOwnMedian($item);
    }

    /**
     * Only the independent reference, with no fallback — null when we hold none.
     *
     * Separate from {@see for()} because "what does an outside source say this
     * is worth" and "what should we judge a comp against" are different
     * questions, and the health report needs the first one on its own.
     */
    public function reference(CatalogItem $item): ?int
    {
        return $this->fromPricecharting($item);
    }

    /**
     * Drop the cached PriceCharting rows.
     *
     * Only needed after a PriceCharting import inside the same process; the
     * rows are reference data and do not move while comps are being judged.
     */
    public function forget(): void
    {
        $this->bySet = [];
    }

    /**
     * PriceCharting's loose price for this exact printing, in cents.
     *
     * Matched on set, collector number and name together. Number alone would
     * hand a card the price of whatever else is printed at that number, and
     * PriceCharting writes numbers plainly ("6") where we may hold "006/025",
     * so both are folded the way the reconciliation folds them.
     */
    private function fromPricecharting(CatalogItem $item): ?int
    {
        if ($item->set_id === null || $item->number === null) {
            return null;
        }

        $number = self::numKey($item->number);
        $name = self::nameKey($item->name);

        if ($name === '') {
            return null;
        }

        $attributes = (array) $item->attributes;
        // The name matters as well as the attribute: Japanese sets print a
        // "Mirror Holofoil", which is the reverse printing under another name,
        // and we store those with variant "holo" so only the name says so.
        $reverse = self::isReverse($attributes['variant'] ?? null)
            || str_contains(strtolower($item->name), 'mirror holo');
        $edition = self::editionKey($attributes['edition'] ?? null);

        $hits = [];

        foreach ($this->setRows($item->set_id) as $row) {
            if ($row['num'] !== $number || $row['name'] === '' || $row['cents'] <= 0) {
                continue;
            }

            if (! str_contains($name, $row['name']) && ! str_contains($row['name'], $name)) {
                continue;
            }

            // Printing has to agree, or the anchor is another card's price.
            // Legendary Collection #86 is $5.59 normal and $895 reverse; Base
            // #17 is $4.24 unlimited and $128.32 first edition. Anchoring one
            // on the other sets a band that rejects every genuine sale.
            if ($row['reverse'] !== $reverse || $row['edition'] !== $edition) {
                continue;
            }

            $hits[$row['cents']] = true;
        }

        // Exactly one price, or several that agree. Base #17 genuinely holds
        // two unlabelled Beedrill rows at $4.24 and $8.99 with nothing to tell
        // them apart — there, no anchor is the honest answer, and the fallback
        // is what we had before.
        return count($hits) === 1 ? (int) array_key_first($hits) : null;
    }

    /**
     * This set's PriceCharting singles, folded once and kept.
     *
     * The sweep asks for an anchor per candidate, so re-reading and re-folding
     * a set's rows on every call would be the same query hundreds of times.
     * They are reference data — safe to hold, unlike the median below.
     *
     * @return array<int, array{num: string, name: string, cents: int}>
     */
    private function setRows(int $setId): array
    {
        if (isset($this->bySet[$setId])) {
            return $this->bySet[$setId];
        }

        return $this->bySet[$setId] = PricechartingProduct::where('set_id', $setId)
            // A sealed product's price would raise the ceiling rather than
            // lower it, and it is not this card in any case.
            ->where('is_sealed', false)
            ->whereNotNull('price_ungraded')
            ->get(['card_name', 'number', 'variant', 'edition', 'price_ungraded'])
            ->map(fn (PricechartingProduct $row) => [
                'num' => self::numKey($row->number),
                'name' => self::nameKey((string) $row->card_name),
                'reverse' => self::isReverse($row->variant),
                'edition' => self::editionKey($row->edition),
                'cents' => (int) $row->price_ungraded,
            ])
            ->all();
    }

    /** What the anchor has always been: this card's own raw median. */
    private function fromOwnMedian(CatalogItem $item): int
    {
        return (int) ($item->marketValues()
            ->whereNull('grading_company_id')
            ->orderByRaw("CASE WHEN state_key IN ('NM', 'SEALED') THEN 0 ELSE 1 END")
            ->value('median') ?? 0);
    }

    /**
     * "006/025", "006" and "6" are the same card.
     *
     * The same fold ReconcileSet uses, plus the set-total suffix: we store
     * plain numbers, but "006/025" turns up, and folded whole it becomes
     * "6025" — a number no card has.
     */
    private static function numKey(?string $number): string
    {
        $n = (string) strtok((string) $number, '/');
        $n = ltrim((string) preg_replace('/[^a-z0-9]+/i', '', strtolower($n)), '0');

        return $n === '' ? '0' : $n;
    }

    /**
     * Is this the reverse-holo printing?
     *
     * Only reverse is tested, not variant equality: PriceCharting splits the
     * reverse holo into its own row but folds holo and normal together, so
     * demanding an exact variant match would strand every holo we hold.
     */
    private static function isReverse(?string $variant): bool
    {
        return str_contains(strtolower((string) $variant), 'reverse');
    }

    /**
     * The printing run, with unlimited and unlabelled treated as the same.
     *
     * PriceCharting leaves edition empty for the unlimited run rather than
     * naming it, so an exact string match would strand every unlimited card.
     */
    private static function editionKey(?string $edition): string
    {
        $e = strtolower(trim((string) $edition));

        return $e === 'unlimited' ? '' : $e;
    }

    /** Fold accents (Pokémon → Pokemon) then strip to alphanumerics. */
    private static function nameKey(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($s)));
    }
}
