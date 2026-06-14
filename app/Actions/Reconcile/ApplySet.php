<?php

namespace App\Actions\Reconcile;

use App\Actions\Catalog\AddSealedProduct;
use App\Actions\Catalog\CreateCatalogItem;
use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ReconciliationChange as ChangeRecord;
use App\Models\Set;
use App\Support\Catalog\IdentityHash;
use App\Support\Reconcile\ReconcileChange;
use App\Support\Verticals\VerticalRegistry;
use Illuminate\Support\Carbon;

/**
 * Applies a set's reconciliation. Auto-applies high-confidence, unambiguous
 * changes — assign Unlimited (FIX_LABEL), add missing editions (ADD_PRINTING),
 * attach the PriceCharting id (LINK) — and queues the rest (errors, missing
 * cards, sealed) for admin review. Every outcome is recorded in
 * reconciliation_changes (audit + queue). Idempotent: ReconcileSet stops
 * proposing a change once it's applied, so re-runs add nothing.
 */
class ApplySet
{
    /** Actions auto-applied when high-confidence; everything else queues. */
    private const AUTO = [ReconcileChange::ADD_PRINTING, ReconcileChange::FIX_LABEL, ReconcileChange::LINK];

    public function __construct(
        protected ReconcileSet $reconcile,
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
        protected AddSealedProduct $addSealed,
        protected VerticalRegistry $registry,
    ) {}

    /** Approve a single queued change (admin review). Returns the affected item id. */
    public function applyStored(ChangeRecord $record): ?int
    {
        $set = $record->set;
        if (! $set) {
            return null;
        }
        $set->loadMissing('productLine.vertical');

        $p = $record->payload ?? [];
        $change = new ReconcileChange(
            action: $record->action, pcId: $record->pc_id, label: $p['label'] ?? '',
            confidence: $record->confidence, reason: $record->reason,
            baseItemId: $p['base_item_id'] ?? null,
            name: $p['name'] ?? null, number: $p['number'] ?? null,
            attributes: $p['attributes'] ?? [], prices: $p['prices'] ?? [],
        );

        $itemId = match ($record->action) {
            ReconcileChange::ADD_PRINTING, ReconcileChange::ADD_ERROR_VARIANT => $this->applyAdd($change, $set),
            ReconcileChange::ADD_CARD => $this->applyNewCard($change, $set),
            ReconcileChange::ADD_SEALED => $this->applySealed($change, $set),
            ReconcileChange::FIX_LABEL => $this->applyFix($change),
            ReconcileChange::LINK => $this->applyLink($change),
            default => null,
        };

        $record->forceFill(['status' => 'applied', 'catalog_item_id' => $itemId, 'applied_at' => Carbon::now()])->save();

        return $itemId;
    }

    public function skip(ChangeRecord $record): void
    {
        $record->forceFill(['status' => 'skipped'])->save();
    }

    /**
     * @return array{applied: int, queued: int}
     */
    public function __invoke(Set $set, bool $write = true): array
    {
        $set->loadMissing('productLine.vertical');
        $applied = 0;
        $queued = 0;

        foreach (($this->reconcile)($set) as $change) {
            $auto = $change->confidence === 'high' && in_array($change->action, self::AUTO, true);

            if ($auto && $write) {
                $itemId = $this->apply($change, $set);
                $this->record($change, $set, 'applied', $itemId);
                $applied++;
            } else {
                $this->record($change, $set, 'pending', null);
                $queued++;
            }
        }

        return ['applied' => $applied, 'queued' => $queued];
    }

    private function apply(ReconcileChange $change, Set $set): ?int
    {
        return match ($change->action) {
            ReconcileChange::FIX_LABEL => $this->applyFix($change),
            ReconcileChange::LINK => $this->applyLink($change),
            ReconcileChange::ADD_PRINTING, ReconcileChange::ADD_ERROR_VARIANT => $this->applyAdd($change, $set),
            default => null,
        };
    }

    private function applyFix(ReconcileChange $change): ?int
    {
        $item = CatalogItem::find($change->catalogItemId);
        if (! $item) {
            return null;
        }

        $attributes = $item->attributes ?? [];
        foreach ($change->diff as $key => [, $new]) {
            $attributes[$key] = $new;
        }
        $item->forceFill([
            'attributes' => $attributes,
            // Link the PriceCharting product too, so the next run sees a clean match.
            'external_ids' => array_merge($item->external_ids ?? [], ['pricecharting_id' => $change->pcId]),
        ]);
        $this->rehash($item);
        $item->save();

        return $item->id;
    }

    private function applyLink(ReconcileChange $change): ?int
    {
        $item = CatalogItem::find($change->catalogItemId);
        if (! $item) {
            return null;
        }

        $item->forceFill(['external_ids' => array_merge($item->external_ids ?? [], ['pricecharting_id' => $change->pcId])])->save();

        return $item->id;
    }

    private function applyAdd(ReconcileChange $change, Set $set): ?int
    {
        $base = $change->baseItemId ? CatalogItem::find($change->baseItemId) : null;
        if (! $base) {
            return null; // no base printing to inherit from — leave for review
        }

        $attributes = array_merge($base->attributes ?? [], $change->attributes);

        $item = ($this->create)(
            vertical: $set->productLine->vertical,
            productLine: $set->productLine,
            set: $set,
            itemType: ItemType::Single,
            name: $base->name,
            number: $base->number,
            attributes: $attributes,
            externalIds: array_merge($base->external_ids ?? [], ['pricecharting_id' => $change->pcId]),
            primaryImagePath: $base->primary_image_path,
        );

        $this->seedFrom($item, $change->prices);

        return $item->id;
    }

    private function applyNewCard(ReconcileChange $change, Set $set): ?int
    {
        $attributes = array_merge([
            'language' => $set->language ?? 'en',
            'rarity' => 'Unknown',
            'variant' => 'normal',
        ], $change->attributes);

        $item = ($this->create)(
            vertical: $set->productLine->vertical, productLine: $set->productLine, set: $set,
            itemType: ItemType::Single, name: (string) $change->name, number: $change->number,
            attributes: $attributes, externalIds: ['pricecharting_id' => $change->pcId],
        );

        $this->seedFrom($item, $change->prices);

        return $item->id;
    }

    /** Seed an item from PriceCharting's ungraded + graded (PSA 10/9/8, BGS 10) prices. */
    private function seedFrom(CatalogItem $item, array $prices): void
    {
        $map = [
            ['key' => 'psa10', 'company' => 'psa', 'grade' => 10.0, 'label' => 'PSA 10'],
            ['key' => 'grade9', 'company' => 'psa', 'grade' => 9.0, 'label' => 'PSA 9'],
            ['key' => 'grade8', 'company' => 'psa', 'grade' => 8.0, 'label' => 'PSA 8'],
            ['key' => 'bgs10', 'company' => 'bgs', 'grade' => 10.0, 'label' => 'BGS 10'],
        ];

        $graded = [];
        foreach ($map as $m) {
            $cents = (int) ($prices[$m['key']] ?? 0);
            if ($cents > 0) {
                $graded[] = ['company' => $m['company'], 'grade' => $m['grade'], 'label' => $m['label'], 'cents' => $cents];
            }
        }

        $ungraded = (int) ($prices['ungraded'] ?? 0);
        if ($ungraded > 0 || $graded !== []) {
            ($this->seed)($item, $ungraded, null, null, $graded);
        }
    }

    private function applySealed(ReconcileChange $change, Set $set): ?int
    {
        $item = ($this->addSealed)($set, [
            'name' => (string) $change->name,
            'sealed_type' => self::sealedType((string) $change->name),
            'language' => $set->language ?? 'en',
            'price_cents' => $change->prices['ungraded'] ?? null,
        ]);

        $item->forceFill(['external_ids' => array_merge($item->external_ids ?? [], ['pricecharting_id' => $change->pcId])])->save();

        return $item->id;
    }

    private static function sealedType(string $name): string
    {
        $n = strtolower($name);

        return match (true) {
            str_contains($n, 'elite trainer') => 'elite_trainer_box',
            str_contains($n, 'booster box') => 'booster_box',
            str_contains($n, 'booster bundle') => 'booster_bundle',
            str_contains($n, 'booster pack') => 'booster_pack',
            str_contains($n, 'blister') => 'blister',
            str_contains($n, 'bundle') => 'bundle',
            str_contains($n, 'tin') => 'tin',
            str_contains($n, 'collection') => 'collection',
            default => 'other',
        };
    }

    /** Recompute identity_hash/base_key after an attribute change. */
    private function rehash(CatalogItem $item): void
    {
        $args = [
            'verticalSlug' => $item->vertical->slug,
            'productLineSlug' => $item->productLine->slug,
            'setKey' => $item->set?->slug,
            'itemType' => $item->item_type->value,
            'name' => $item->name,
            'number' => $item->number,
        ];
        $attributes = $item->attributes ?? [];
        $variantKeys = $this->registry->get($item->vertical->slug)->variantDefiningKeys($item->item_type->value);

        $item->forceFill([
            'identity_hash' => IdentityHash::compute(...$args, attributes: $attributes),
            'base_key' => IdentityHash::compute(...$args, attributes: array_diff_key($attributes, array_flip($variantKeys))),
        ]);
    }

    private function record(ReconcileChange $change, Set $set, string $status, ?int $itemId): void
    {
        ChangeRecord::updateOrCreate(
            ['pc_id' => $change->pcId],
            [
                'set_id' => $set->id,
                'action' => $change->action,
                'reason' => $change->reason,
                'confidence' => $change->confidence,
                'status' => $status,
                'catalog_item_id' => $itemId,
                'payload' => [
                    'label' => $change->label,
                    'name' => $change->name,
                    'number' => $change->number,
                    'attributes' => $change->attributes,
                    'diff' => $change->diff,
                    'prices' => $change->prices,
                    'base_item_id' => $change->baseItemId,
                ],
                'applied_at' => $status === 'applied' ? Carbon::now() : null,
            ],
        );
    }
}
