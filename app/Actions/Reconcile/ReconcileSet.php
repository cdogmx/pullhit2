<?php

namespace App\Actions\Reconcile;

use App\Models\CatalogItem;
use App\Models\PricechartingProduct;
use App\Models\Set;
use App\Support\Reconcile\ReconcileChange;
use Illuminate\Support\Collection;

/**
 * Diff one set against its PriceCharting products and propose changes. The engine
 * of the catalog reconciliation: surfaces missing printings/editions (1st Edition,
 * Shadowless, error variants), missing cards, sealed product, label fixes, and
 * links — each confidence-scored. Read-only; {@see ApplySet} performs the writes.
 */
class ReconcileSet
{
    /** @return array<int, ReconcileChange> */
    public function __invoke(Set $set): array
    {
        $pcProducts = PricechartingProduct::where('set_id', $set->id)->get();
        $ours = CatalogItem::where('set_id', $set->id)->get();

        // If PriceCharting tags any edition for this set, an untagged card is the
        // Unlimited print; otherwise the set has no edition axis (modern sets).
        $setHasEditions = $pcProducts->contains(fn (PricechartingProduct $p) => $p->edition !== null);

        $oursByNumber = $ours->groupBy(fn (CatalogItem $i) => self::numKey($i->number));

        $changes = [];
        foreach ($pcProducts as $pc) {
            $change = $pc->is_sealed
                ? $this->classifySealed($pc, $ours)
                : $this->classifyCard($pc, $oursByNumber, $setHasEditions);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * @param  Collection<string, Collection<int, CatalogItem>>  $oursByNumber
     */
    private function classifyCard(PricechartingProduct $pc, Collection $oursByNumber, bool $setHasEditions): ?ReconcileChange
    {
        if ($pc->number === null) {
            return null;
        }

        $edition = $pc->edition ?? ($setHasEditions ? 'unlimited' : null);
        $prices = $this->prices($pc);
        $candidates = $oursByNumber->get(self::numKey($pc->number)) ?? collect();

        if ($candidates->isEmpty()) {
            return new ReconcileChange(
                action: ReconcileChange::ADD_CARD,
                pcId: $pc->pc_id, label: $pc->product_name,
                confidence: 'low', reason: 'number_absent',
                name: $pc->card_name, number: $pc->number,
                attributes: array_filter(['variant' => $pc->variant, 'edition' => $edition, 'finish' => $pc->finish], fn ($v) => $v !== null),
                prices: $prices,
            );
        }

        $named = $candidates->filter(fn (CatalogItem $i) => self::nameMatch($i->name, (string) $pc->card_name));
        if ($named->isEmpty()) {
            return new ReconcileChange(
                action: ReconcileChange::ADD_CARD,
                pcId: $pc->pc_id, label: $pc->product_name,
                confidence: 'low', reason: 'name_mismatch',
                name: $pc->card_name, number: $pc->number, prices: $prices,
            );
        }

        // The base printing (non-reverse, no error tag) sets holo-ness + inheritance.
        $base = $named->first(fn (CatalogItem $i) => ($i->attributes['variant'] ?? 'normal') !== 'reverse_holo'
            && ($i->attributes['finish'] ?? null) === null);
        $baseVariant = $base ? ($base->attributes['variant'] ?? 'normal') : 'normal';

        // PriceCharting only tags "[Reverse]"; otherwise it's the base holo-ness.
        $variant = $pc->variant ?? $baseVariant;
        $finish = $pc->finish;

        $matches = fn (CatalogItem $i, ?string $ed) => ($i->attributes['variant'] ?? 'normal') === $variant
            && ($i->attributes['finish'] ?? null) === $finish
            && ($i->attributes['edition'] ?? null) === $ed;

        // Already have this exact printing → link the PriceCharting id if needed.
        $exact = $named->first(fn (CatalogItem $i) => $matches($i, $edition));
        if ($exact) {
            if (($exact->external_ids['pricecharting_id'] ?? null) === $pc->pc_id) {
                return null;
            }

            return new ReconcileChange(
                action: ReconcileChange::LINK,
                pcId: $pc->pc_id, label: $pc->product_name,
                confidence: 'high', reason: 'exact_match',
                catalogItemId: $exact->id, prices: $prices,
            );
        }

        // The Unlimited print of a card we hold as edition-less → just label it.
        if ($finish === null && $edition === 'unlimited') {
            $unedited = $named->first(fn (CatalogItem $i) => $matches($i, null));
            if ($unedited) {
                return new ReconcileChange(
                    action: ReconcileChange::FIX_LABEL,
                    pcId: $pc->pc_id, label: $pc->product_name,
                    confidence: 'high', reason: 'assign_unlimited',
                    catalogItemId: $unedited->id,
                    diff: ['edition' => [null, 'unlimited']],
                    prices: $prices,
                );
            }
        }

        // Otherwise this printing is missing — add it, inheriting from the base card.
        return new ReconcileChange(
            action: $finish !== null ? ReconcileChange::ADD_ERROR_VARIANT : ReconcileChange::ADD_PRINTING,
            pcId: $pc->pc_id, label: $pc->product_name,
            confidence: $base ? 'high' : 'low',
            reason: $base ? 'missing_printing' : 'no_base_printing',
            baseItemId: $base?->id,
            name: $pc->card_name, number: $pc->number,
            attributes: array_filter(['variant' => $variant, 'edition' => $edition, 'finish' => $finish], fn ($v) => $v !== null),
            prices: $prices,
        );
    }

    /**
     * @param  Collection<int, CatalogItem>  $ours
     */
    private function classifySealed(PricechartingProduct $pc, Collection $ours): ?ReconcileChange
    {
        // Already linked? skip.
        $linked = $ours->first(fn (CatalogItem $i) => ($i->external_ids['pricecharting_id'] ?? null) === $pc->pc_id);
        if ($linked) {
            return null;
        }

        // Sealed taxonomy (booster box vs ETB vs …) is fuzzy — always queue.
        return new ReconcileChange(
            action: ReconcileChange::ADD_SEALED,
            pcId: $pc->pc_id, label: $pc->product_name,
            confidence: 'low', reason: 'sealed',
            name: $pc->card_name, prices: $this->prices($pc),
        );
    }

    /** @return array<string, int|null> */
    private function prices(PricechartingProduct $pc): array
    {
        return [
            'ungraded' => $pc->price_ungraded,
            'grade8' => $pc->price_grade8,
            'grade9' => $pc->price_grade9,
            'grade95' => $pc->price_grade95,
            'psa10' => $pc->price_psa10,
            'bgs10' => $pc->price_bgs10,
        ];
    }

    private static function numKey(?string $number): string
    {
        $n = ltrim((string) preg_replace('/[^a-z0-9]+/i', '', strtolower((string) $number)), '0');

        return $n === '' ? '0' : $n;
    }

    private static function nameMatch(string $a, string $b): bool
    {
        $na = self::nameKey($a);
        $nb = self::nameKey($b);

        return $na !== '' && $nb !== '' && (str_contains($na, $nb) || str_contains($nb, $na));
    }

    /** Fold accents (Pokémon → Pokemon) then strip to alphanumerics. */
    private static function nameKey(string $s): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii($s)));
    }
}
