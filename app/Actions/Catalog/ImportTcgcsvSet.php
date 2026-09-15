<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\CardName;
use App\Support\Catalog\TcgcsvClient;
use App\Support\Catalog\TcgcsvGame;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
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
    /**
     * Skip numbers compared the way the importer writes them, so a caller can
     * pass "037" or "37" and mean the same card.
     *
     * @param  array<int, string|int>  $skips
     * @return array<int, string>
     */
    private function normalizeSkips(array $skips): array
    {
        return array_map(fn ($n) => $this->cleanNumber((string) $n), $skips);
    }

    public function __invoke(
        int $groupId,
        bool $withPrices = true,
        bool $withImages = true,
        TcgcsvGame $game = TcgcsvGame::Pokemon,
        array $skipNumbers = [],
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

            // Numbers this catalog already holds somewhere else. A group can
            // overlap a set we curate by hand — ME: Mega Evolution Promo carries
            // 037-063, which live in the First Partners sets with hand-uploaded
            // art and 45 user collections against them — and importing those
            // again would make a second copy of a card people already own,
            // because identity includes the set.
            if ($skipNumbers !== [] && in_array($number, $this->normalizeSkips($skipNumbers), true)) {
                continue;
            }
            $rarity = Arr::get($extended->get('Rarity', []), 'value') ?: 'Unknown';
            $name = $this->cleanName((string) ($product['name'] ?? $product['cleanName'] ?? 'Unknown'), $number);

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

            // A product we already hold stays where it is. Sets here are ours,
            // not TCGplayer's: their promo group carries the 30th Celebration
            // promos, and we file those under the expansion they belong to. A
            // re-import that went by group alone would make a second copy in
            // the promo set and split the card's comps across both.
            $existingSet = $this->setHolding($productId);

            foreach ($this->variants($prices, $game) as $variant => $anchor) {
                $item = ($this->create)(
                    vertical: $vertical,
                    productLine: $productLine,
                    set: $existingSet ?? $set,
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
            $subType = (string) ($price['subTypeName'] ?? '');
            $variant = self::SUBTYPE_VARIANT[$subType] ?? null;

            // An unmapped subtype used to fall back to 'normal', and since $out is
            // keyed by variant that silently overwrote the real Normal price with
            // whatever the unknown finish cost. Skip it and say so instead — a new
            // TCGplayer finish should be added to SUBTYPE_VARIANT deliberately.
            if ($variant === null) {
                Log::warning('TCGCSV: unmapped price subtype, row skipped', [
                    'subTypeName' => $subType,
                    'productId' => $price['productId'] ?? null,
                ]);

                continue;
            }

            $out[$variant] = $this->anchorCents($price);
        }

        // Every subtype was unknown — still produce the card, just unvalued, rather
        // than dropping it from the catalog.
        return $out === [] ? ['normal' => 0] : $out;
    }

    /** @param  array<string, mixed>  $price */
    protected function anchorCents(array $price): int
    {
        $value = $price['marketPrice'] ?? $price['midPrice'] ?? $price['lowPrice'] ?? null;

        return $value ? (int) round((float) $value * 100) : 0;
    }

    /** @param  array<string, mixed>  $group */
    /**
     * The set a TCGplayer product already lives in here, when it is not the one
     * this group maps to — so a card filed under its own expansion is refreshed
     * there rather than duplicated back into the group's set.
     */
    protected function setHolding(string $productId): ?Set
    {
        if ($productId === '') {
            return null;
        }

        $item = CatalogItem::query()
            ->where('external_ids->tcgplayer_product_id', $productId)
            ->with('set')
            ->first();

        return $item?->set;
    }

    /**
     * The era a set belongs to, read off the sets that already share its code
     * prefix — "ME05" and "MEG" are both Mega Evolution, so "ME" is too.
     *
     * Only answers when the siblings agree: a prefix that spans two eras tells
     * us nothing, and a wrong series is worse than a blank one because it files
     * the set somewhere a person will not look.
     */
    /**
     * The era a set belongs to, read off the sets that already share its code
     * prefix — "ME05" is Mega Evolution, so plain "ME" is too.
     *
     * Compared on the leading run of letters exactly, not as a prefix search:
     * "ME%" also matches "MEW", which is Scarlet & Violet 151, and a code
     * that spans two eras tells us nothing. Only answers when the siblings
     * agree, because filing a set under the wrong era hides it just as
     * thoroughly as leaving it blank, and less visibly.
     */
    protected function seriesFor(int $productLineId, ?string $code): ?string
    {
        if (! $code || ! preg_match('/^([A-Za-z]{2,})/', $code, $m)) {
            return null;
        }

        $prefix = mb_strtolower($m[1]);

        $series = Set::query()
            ->where('product_line_id', $productLineId)
            ->whereNotNull('series')
            ->whereNotNull('code')
            ->get(['code', 'series'])
            ->filter(function ($set) use ($prefix) {
                preg_match('/^([A-Za-z]+)/', (string) $set->code, $sm);

                return isset($sm[1]) && mb_strtolower($sm[1]) === $prefix;
            })
            ->pluck('series')
            ->unique()
            ->values();

        return $series->count() === 1 ? $series->first() : null;
    }

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
            // Never touch a code the game's own importer already set — it knows the
            // real printed code ("WHT"), where TCGplayer often carries none or a
            // series prefix. Guarding only against a null $code was not enough:
            // "SV: White Flare" parses to "SV", which is truthy, so importing the
            // English group would have relabelled WHT/PRE/BLK as "SV".
            'code' => $set->code ?: $code,
            'language' => 'en',
            // Browse drills brand → series → set, and a set with no series hangs
            // off none of those tiles: importing "ME: 30th Celebration" put 184
            // cards in the catalog that could not be reached from the Pokemon
            // browse page at all. Inferred from the sets that already share this
            // one's code prefix, never overwritten — the game's own importer
            // knows the real era and this is only filling a blank.
            'series' => $set->series ?: $this->seriesFor($productLineId, $set->code ?: $code),
            // Clean name links this set to a same-named set in another language.
            'set_family' => $name,
            'released_at' => isset($group['publishedOn']) ? substr((string) $group['publishedOn'], 0, 10) : null,
            'external_ids' => array_merge((array) ($set->external_ids ?? []), [
                'tcgplayer_group_id' => $groupId,
            ]),
        ])->save();

        return $set;
    }

    /** @see CardName::clean() */
    protected function cleanName(string $name, ?string $number = null): string
    {
        $name = CardName::clean($name);

        // TCGplayer names promo products "Oricorio ex - 024", where CardName
        // only strips the "N/M" form. Left in, an imported promo reads
        // "Oricorio ex - 024" beside a hand-entered "Bulbasaur" from the same
        // run of cards.
        //
        // The number may be followed by a qualifier that has to survive:
        // "Drifloon - 005 (Cosmos Holo)" and "Alakazam - 003 [Staff]" are
        // distinct printings sharing a number with the plain card, and the
        // parenthetical is the only thing telling them apart.
        //
        // Only stripped when the number IS this card's, so a name that simply
        // ends in a numeral cannot be truncated by accident.
        if ($number === null || $number === '') {
            return $name;
        }

        $stripped = preg_replace(
            '/\s*-\s*0*'.preg_quote($number, '/').'(?=\s*[(\[]|\s*$)/i',
            '',
            $name,
        );

        $stripped = trim((string) preg_replace('/\s{2,}/', ' ', (string) $stripped));

        return $stripped !== '' ? $stripped : $name;
    }

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
