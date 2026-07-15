<?php

namespace App\Actions\Catalog;

use App\Actions\Valuation\SeedSyntheticValuation;
use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\TcgcsvClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Import an ENGLISH Pokémon set from TCGCSV (TCGplayer category 3) into the
 * catalog — for brand-new sets pokemontcg.io hasn't published yet (it lags weeks
 * behind release). The English sibling of ImportJapaneseSet: upsert the
 * (language=en) Set, create a catalog_item per card finish via CreateCatalogItem,
 * store images, seed estimated values from TCGplayer market prices.
 *
 * Normalises the TCGCSV data to pokemontcg.io conventions — clean card names
 * (TCGCSV appends "- 003/084" to some) and zero-stripped collector numbers
 * ("003" → "3") — so a later `catalog:import-set` refines the same rows instead
 * of duplicating them. Only cards (products with a collector Number) are imported;
 * sealed products come via `catalog:import-sealed`.
 */
class ImportEnglishTcgcsvSet
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
    public function __invoke(int $groupId, bool $withPrices = true, bool $withImages = true): array
    {
        $group = collect($this->client->groups(TcgcsvClient::POKEMON))->firstWhere('groupId', $groupId)
            ?? throw new RuntimeException("Pokemon English group [{$groupId}] not found");

        $products = $this->client->products($groupId, TcgcsvClient::POKEMON);
        $pricesByProduct = collect($this->client->prices($groupId, TcgcsvClient::POKEMON))->groupBy('productId');

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $pokemon = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'pokemon'],
            ['name' => 'Pokémon'],
        );
        $set = $this->upsertSet($pokemon->id, $group);

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
                $imageUrl = $this->images->store("en-{$groupId}", $productId, $product['imageUrl'] ?? null);
                if ($imageUrl) {
                    $imageCount++;
                }
            }

            $externalIds = array_filter([
                'tcgplayer_product_id' => $product['productId'] ?? null,
                'tcgplayer_image' => $product['imageUrl'] ?? null,
            ]);

            foreach ($this->variants(($pricesByProduct->get($product['productId'] ?? null) ?? collect())->all()) as $variant => $anchor) {
                $item = ($this->create)(
                    vertical: $vertical,
                    productLine: $pokemon,
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
     * One (variant => anchor-cents) per finish present in the price rows; falls
     * back to a single un-valued Normal when the set has no prices yet (pre-release).
     *
     * @param  array<int, array<string, mixed>>  $prices
     * @return array<string, int>
     */
    protected function variants(array $prices): array
    {
        if ($prices === []) {
            return ['normal' => 0];
        }

        $out = [];
        foreach ($prices as $price) {
            $variant = self::SUBTYPE_VARIANT[$price['subTypeName'] ?? ''] ?? 'normal';
            $value = $price['marketPrice'] ?? $price['midPrice'] ?? $price['lowPrice'] ?? null;
            $out[$variant] = $value ? (int) round((float) $value * 100) : 0;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $group */
    protected function upsertSet(int $productLineId, array $group): Set
    {
        $fullName = $group['name'] ?? ('Group '.($group['groupId'] ?? ''));

        // TCGplayer prefixes the set code: "ME05: Pitch Black" → code "ME05",
        // name "Pitch Black".
        $code = null;
        $name = $fullName;
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.+)$/', $fullName, $m)) {
            $code = $m[1];
            $name = trim($m[2]);
        }

        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where('external_ids->tcgplayer_group_id', (string) $group['groupId'])
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => Str::slug($name) ?: 'set-'.($group['groupId'] ?? 'x'),
            'name' => $name,
            'code' => $code,
            'language' => 'en',
            // Clean name links this set to a same-named JP set for cross-language.
            'set_family' => $name,
            'released_at' => isset($group['publishedOn']) ? substr((string) $group['publishedOn'], 0, 10) : null,
            'external_ids' => ['tcgplayer_group_id' => (string) $group['groupId']],
        ])->save();

        return $set;
    }

    /**
     * Strip a trailing " - 003/084" collector-number suffix TCGCSV appends to some
     * English card names, so they match pokemontcg.io's clean names.
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
