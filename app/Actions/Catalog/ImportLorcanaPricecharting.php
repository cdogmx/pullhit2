<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Pricecharting\CsvClient;
use App\Support\Pricecharting\PriceGuideParser;
use Illuminate\Support\Str;

/**
 * Source Lorcana pricing (and the sets lorcana-api.com doesn't carry yet) from
 * PriceCharting's `lorcana-cards` price guide. For sets we already imported from
 * lorcana-api, this only seeds valuations onto the existing items. For sets that
 * have no match (Wilds Unknown, Promo, Illumineer's Quest: Deep Trouble), it
 * creates one catalog_item per card number — names/numbers/prices only; images
 * and game stats (ink/cost/lore) are backfilled by re-running the lorcana-api
 * importer once they add those sets. Idempotent.
 *
 * PriceCharting card-column mapping (its video-game columns repurposed for TCG):
 * loose=raw, graded=PSA 9, new=PSA 8, box-only=PSA 9.5, manual-only=PSA 10,
 * bgs-10=BGS 10. Loose seeds the NM anchor; the graded columns seed real graded
 * tiers via SeedSyntheticValuation's $gradedAnchors. eBay sold comps later
 * replace these synthetic estimates per card.
 */
class ImportLorcanaPricecharting
{
    /** PriceCharting graded price column => [company, grade, label]. */
    private const GRADED = [
        'price_psa10' => ['psa', 10.0, 'PSA 10'],
        'price_grade95' => ['psa', 9.5, 'PSA 9.5'],
        'price_grade9' => ['psa', 9.0, 'PSA 9'],
        'price_grade8' => ['psa', 8.0, 'PSA 8'],
        'price_bgs10' => ['bgs', 10.0, 'BGS 10'],
    ];

    public function __construct(
        protected CsvClient $csv,
        protected PriceGuideParser $parser,
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @return array{sets: array<int, array{set: string, status: string, priced: int, created: int}>}
     */
    public function __invoke(bool $createMissing = true): array
    {
        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $lorcana = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'lorcana'],
            ['name' => 'Disney Lorcana'],
        );

        // Existing lorcana sets indexed by normalized name (a leading "The" is
        // dropped so PriceCharting's "First Chapter" matches "The First Chapter").
        $existing = [];
        foreach (Set::where('product_line_id', $lorcana->id)->get() as $set) {
            $existing[$this->norm($set->name)] = $set;
        }

        // Collapse the guide to one representative row per (set, number): the base
        // non-foil printing where present (its loose price is the raw-card anchor).
        $bySet = [];
        foreach (($this->parser)($this->csv->fetch('lorcana-cards')) as $row) {
            if ($row['is_sealed'] || $row['number'] === null) {
                continue; // singles only — sealed product isn't a card
            }

            $name = $this->setName($row['set_name']);
            $key = $this->norm($name);
            $num = (string) $row['number'];

            $bySet[$key]['name'] = $name;
            $bySet[$key]['release'] ??= $row['release_date'];
            $current = $bySet[$key]['cards'][$num] ?? null;
            if ($current === null || ($this->isBase($row) && ! $this->isBase($current))) {
                $bySet[$key]['cards'][$num] = $row;
            }
        }

        $summaries = [];

        foreach ($bySet as $key => $data) {
            $set = $existing[$key] ?? null;
            $creating = $set === null;

            if ($creating) {
                if (! $createMissing) {
                    $summaries[] = ['set' => $data['name'], 'status' => 'skipped (new)', 'priced' => 0, 'created' => 0];

                    continue;
                }
                $set = $this->createSet($lorcana->id, $data['name'], $data['release']);
            }

            // For an existing set, price its items in place (match by number).
            $items = $creating ? collect() : CatalogItem::query()
                ->where('product_line_id', $lorcana->id)
                ->where('set_id', $set->id)
                ->get()
                ->keyBy('number');

            $priced = 0;
            $created = 0;

            foreach ($data['cards'] as $num => $row) {
                $item = $creating
                    ? $this->createItem($vertical, $lorcana, $set, $row)
                    : $items->get($num);

                if ($item === null) {
                    continue; // PriceCharting has a card lorcana-api didn't — skip
                }
                $creating ? $created++ : $this->enrichExternalIds($item, $row);

                if ($this->priceItem($item, $row)) {
                    $priced++;
                }
            }

            $summaries[] = [
                'set' => $set->name,
                'status' => $creating ? 'created' : 'existing',
                'priced' => $priced,
                'created' => $created,
            ];
        }

        return ['sets' => $summaries];
    }

    /** Seed PriceCharting loose + graded prices onto an item. Returns true if any price applied. */
    private function priceItem(CatalogItem $item, array $row): bool
    {
        $anchor = (int) ($row['price_ungraded'] ?? 0);

        $graded = [];
        foreach (self::GRADED as $col => [$company, $grade, $label]) {
            $cents = (int) ($row[$col] ?? 0);
            if ($cents > 0) {
                $graded[] = ['company' => $company, 'grade' => $grade, 'label' => $label, 'cents' => $cents];
            }
        }

        if ($anchor <= 0 && $graded === []) {
            return false;
        }

        ($this->seed)($item, $anchor, gradedAnchors: $graded);

        return true;
    }

    private function createItem(Vertical $vertical, ProductLine $lorcana, Set $set, array $row): CatalogItem
    {
        $attributes = array_filter([
            'language' => 'en',
            'variant' => 'normal', // Lorcana has no holo axis; alt-arts are own numbers
            'rarity' => $this->rarity($row['finish']),
        ], fn ($v) => $v !== null && $v !== '');

        return ($this->create)(
            vertical: $vertical,
            productLine: $lorcana,
            set: $set,
            itemType: ItemType::Single,
            name: $row['card_name'],
            number: (string) $row['number'],
            attributes: $attributes,
            externalIds: $this->externalIds($row),
        );
    }

    /** Add PriceCharting/TCGplayer ids to an existing item without re-hashing it. */
    private function enrichExternalIds(CatalogItem $item, array $row): void
    {
        $merged = array_merge($item->external_ids ?? [], $this->externalIds($row));
        if ($merged !== ($item->external_ids ?? [])) {
            $item->forceFill(['external_ids' => $merged])->save();
        }
    }

    /** @return array<string, string> */
    private function externalIds(array $row): array
    {
        return array_filter([
            'pricecharting_id' => (string) $row['pc_id'],
            'tcgplayer_product_id' => $row['tcg_id'] !== null ? (string) $row['tcg_id'] : null,
        ]);
    }

    private function createSet(int $productLineId, string $name, ?string $release): Set
    {
        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where('external_ids->pricecharting_console', 'Lorcana '.$name)
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => Str::slug($name),
            'name' => $name,
            'language' => 'en',
            'set_family' => $name,
            'released_at' => $release ?: null,
            'external_ids' => ['pricecharting_console' => 'Lorcana '.$name],
        ])->save();

        return $set;
    }

    /** PriceCharting prefixes every Lorcana console with "Lorcana "; strip it. */
    private function setName(string $consoleSetName): string
    {
        return trim(preg_replace('/^Lorcana\s+/i', '', $consoleSetName)) ?: $consoleSetName;
    }

    /** A base printing carries no edition/variant/finish tag — its loose price is the raw anchor. */
    private function isBase(array $row): bool
    {
        return $row['edition'] === null && $row['variant'] === null && $row['finish'] === null;
    }

    /** Infer rarity for created cards from the bracket-tag finish (PriceCharting has no rarity column). */
    private function rarity(?string $finish): ?string
    {
        return match (true) {
            $finish === null => null,
            str_contains($finish, 'enchanted') => 'Enchanted',
            str_contains($finish, 'iconic') => 'Iconic',
            str_contains($finish, 'epic') => 'Epic',
            default => null,
        };
    }

    private function norm(string $name): string
    {
        $n = (string) preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($name)));

        return (string) preg_replace('/^the/', '', $n);
    }
}
