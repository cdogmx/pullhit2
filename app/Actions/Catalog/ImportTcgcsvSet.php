<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\TcgcsvClient;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import a set from TCGCSV (a mirror of TCGplayer) by group id — our "day one"
 * source for a set the per-game APIs haven't published yet, since they lag
 * release by weeks (pokemontcg.io for English Pokémon, lorcana-api.com for
 * Lorcana). Upserts the Set, creates a catalog_item per card via
 * CreateCatalogItem, stores images, and seeds estimated values from TCGplayer
 * market prices.
 *
 * Normalises to the conventions each game's own importer uses — clean card
 * names, zero-stripped collector numbers ("003/084" → "3"), and that game's
 * variant axis — so when that importer catches up it REFINES these rows instead
 * of duplicating them. The set is matched by slug as well as source id for the
 * same reason (see upsertSet). Only cards (products with a collector Number) are
 * imported; sealed products come via `catalog:import-sealed`.
 */
class ImportTcgcsvSet
{
    /** TCGplayer subtype names → our `variant` enum. */
    private const SUBTYPE_VARIANT = [
        'Normal' => 'normal',
        'Holofoil' => 'holo',
        'Reverse Holofoil' => 'reverse_holo',
    ];

    public function __construct(
        protected TcgcsvClient $client,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
        protected SeedSyntheticValuation $seed,
    ) {}

    /**
     * @return array{set: string, items: int, valued: int, images: int}
     */
    public function __invoke(
        int $groupId,
        bool $withPrices = true,
        bool $withImages = true,
        TcgcsvGame $game = TcgcsvGame::Pokemon,
    ): array {
        $category = $game->categoryId();

        $group = collect($this->client->groups($category))->firstWhere('groupId', $groupId)
            ?? throw new RuntimeException("TCGCSV {$game->value} group [{$groupId}] not found");

        $products = $this->client->products($groupId, $category);
        $pricesByProduct = collect($this->client->prices($groupId, $category))->groupBy('productId');

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $productLine = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => $game->value],
            ['name' => $game->productLineName()],
        );
        $set = $this->upsertSet($productLine->id, $group);

        $items = 0;
        $valued = 0;
        $imageCount = 0;

        foreach ($products as $product) {
            $extended = collect($product['extendedData'] ?? [])->keyBy('name');
            $rawNumber = Arr::get($extended->get('Number', []), 'value');

            // No collector number → a sealed product; handled by catalog:import-sealed.
            if (! $rawNumber) {
                continue;
            }

            $productId = (string) ($product['productId'] ?? '');
            $number = $this->cleanNumber((string) $rawNumber);
            $rarity = Arr::get($extended->get('Rarity', []), 'value') ?: 'Unknown';
            $name = $this->cleanName((string) ($product['name'] ?? $product['cleanName'] ?? 'Unknown'));

            $imageUrl = null;
            if ($withImages) {
                $imageUrl = $this->images->store(
                    "{$game->value}-{$groupId}",
                    $productId,
                    $product['imageUrl'] ?? null,
                    $game->imageLine(),
                );
                if ($imageUrl) {
                    $imageCount++;
                }
            }

            $externalIds = array_filter([
                'tcgplayer_product_id' => $product['productId'] ?? null,
                'tcgplayer_image' => $product['imageUrl'] ?? null,
            ]);

            $prices = ($pricesByProduct->get($product['productId'] ?? null) ?? collect())->all();

            foreach ($this->variants($prices, $game) as $variant => $anchor) {
                $item = ($this->create)(
                    vertical: $vertical,
                    productLine: $productLine,
                    set: $set,
                    itemType: ItemType::Single,
                    name: $name,
                    number: $number,
                    attributes: array_filter([
                        'language' => 'en',
                        'rarity' => $rarity,
                        'variant' => $variant,
                    ], fn ($v) => $v !== null && $v !== ''),
                    externalIds: $externalIds,
                    primaryImagePath: $imageUrl,
                );
                $items++;

                if ($withPrices && $anchor > 0) {
                    ($this->seed)($item, $anchor);
                    $valued++;
                }
            }
        }

        return ['set' => $set->name, 'items' => $items, 'valued' => $valued, 'images' => $imageCount];
    }

    /**
     * One (variant => anchor-cents) per catalog row this product should produce.
     * For a game with a finish axis that's one per priced finish; for one without
     * (Lorcana) every finish collapses into the single `normal` row, anchored on
     * the base price so a foil premium doesn't inflate the card's value. Falls
     * back to a single un-valued Normal when the set has no prices yet (pre-release).
     *
     * @param  array<int, array<string, mixed>>  $prices
     * @return array<string, int>
     */
    protected function variants(array $prices, TcgcsvGame $game = TcgcsvGame::Pokemon): array
    {
        if ($prices === []) {
            return ['normal' => 0];
        }

        if (! $game->hasFinishVariants()) {
            $base = collect($prices)->firstWhere('subTypeName', 'Normal') ?? $prices[0];

            return ['normal' => $this->anchorCents($base)];
        }

        $out = [];
        foreach ($prices as $price) {
            $variant = self::SUBTYPE_VARIANT[$price['subTypeName'] ?? ''] ?? 'normal';
            $out[$variant] = $this->anchorCents($price);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $price */
    protected function anchorCents(array $price): int
    {
        $value = $price['marketPrice'] ?? $price['midPrice'] ?? $price['lowPrice'] ?? null;

        return $value ? (int) round((float) $value * 100) : 0;
    }

    /** @param  array<string, mixed>  $group */
    protected function upsertSet(int $productLineId, array $group): Set
    {
        $fullName = $group['name'] ?? ('Group '.($group['groupId'] ?? ''));

        // TCGplayer prefixes the set code on some games: "ME05: Pitch Black" →
        // code "ME05", name "Pitch Black".
        $code = null;
        $name = $fullName;
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.+)$/', $fullName, $m)) {
            $code = $m[1];
            $name = trim($m[2]);
        }

        $slug = Str::slug($name) ?: 'set-'.($group['groupId'] ?? 'x');
        $groupId = (string) $group['groupId'];

        // Match on the TCGplayer group id first, then fall back to the slug. The
        // game's own importer keys sets by ITS source id, so without the slug
        // fallback its later run would create a second row for the same set — and
        // a full set of duplicate cards under it. Matched either way, external ids
        // are merged so neither source's id is dropped.
        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where(fn ($q) => $q
                ->where('external_ids->tcgplayer_group_id', $groupId)
                ->orWhere('slug', $slug))
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => $slug,
            'name' => $name,
            // Never clear a code the game's own importer already set — it knows the
            // real printed code ("WUN"), where TCGplayer often carries none.
            'code' => $code ?: $set->code,
            'language' => 'en',
            // Clean name links this set to a same-named set in another language.
            'set_family' => $name,
            'released_at' => isset($group['publishedOn']) ? substr((string) $group['publishedOn'], 0, 10) : null,
            'external_ids' => array_merge((array) ($set->external_ids ?? []), [
                'tcgplayer_group_id' => $groupId,
            ]),
        ])->save();

        return $set;
    }

    /**
     * Strip a trailing " - 003/084" collector-number suffix TCGCSV appends to some
     * card names, so they match the clean names the per-game APIs publish. Anchored
     * on the slashed number, so a name that legitimately ends in " - Version"
     * (every Lorcana character) is left alone.
     */
    protected function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s*-\s*[0-9A-Za-z]+\/[0-9A-Za-z]+\s*$/', '', $name)) ?: $name;
    }

    /** "003/084" → "3" (leading number, zero-stripped, matching EN storage). */
    protected function cleanNumber(string $raw): ?string
    {
        $head = trim(explode('/', $raw)[0]);
        if ($head === '') {
            return null;
        }

        // Zero-strip a purely numeric number ("003" → "3"); keep alphanumerics
        // (promos like "SWSH004") verbatim.
        if (ctype_digit($head)) {
            $head = ltrim($head, '0');

            return $head === '' ? '0' : $head;
        }

        return $head;
    }
}
