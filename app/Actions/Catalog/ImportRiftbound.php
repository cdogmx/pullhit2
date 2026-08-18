<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\ProductLine;
use App\Models\Set;
use App\Models\Vertical;
use App\Support\Catalog\CardImageStore;
use App\Support\Catalog\RiftboundApiClient;
use Illuminate\Support\Str;

/**
 * Import Riftbound (Riot's League of Legends TCG) into the catalog: upsert the
 * riftbound product line and its sets, a catalog_item per card, and each card
 * image into our bucket. Idempotent — keyed on the card's stable gallery id, so
 * a re-run upserts rather than duplicating. No price seeding; valuations come
 * from eBay like every other line.
 *
 * Numbering deserves a note. The feed's `collectorNumber` is NOT unique within a
 * set: a token ("UNL-T01") carries collectorNumber 1 and collides with the real
 * card 001. Since identity_hash is built from set + name + number, importing on
 * collectorNumber would fold distinct cards together. The number therefore comes
 * from `publicCode` with its set prefix stripped — "UNL-001/219" becomes
 * "001/219" and "UNL-T01" becomes "T01", which matches how Pokémon numbers read
 * and is collision-free across all 1,189 cards.
 *
 * Riftbound's facets reuse existing vertical attributes rather than adding new
 * ones, the way Lorcana reuses `type`/`illustrator`: `faction` carries the card's
 * domain (Chaos, Order, Fury, …) and `cost` carries its energy.
 */
class ImportRiftbound
{
    public function __construct(
        protected RiftboundApiClient $api,
        protected CardImageStore $images,
        protected CreateCatalogItem $create,
    ) {}

    /**
     * @return array{cards: int, images: int, sets: array<string, int>, sample: array<int, array<string, mixed>>}
     */
    public function __invoke(bool $dryRun = false, bool $withImages = true, ?int $limit = null): array
    {
        $cards = $this->api->cards();

        if ($limit !== null) {
            $cards = array_slice($cards, 0, $limit);
        }

        $bySet = [];
        foreach ($cards as $card) {
            $bySet[$this->setName($card)] = ($bySet[$this->setName($card)] ?? 0) + 1;
        }

        if ($dryRun) {
            return [
                'cards' => count($cards),
                'images' => 0,
                'sets' => $bySet,
                'sample' => array_map(fn ($c) => [
                    'number' => $this->number($c),
                    'name' => $c['name'] ?? null,
                    'set' => $this->setName($c),
                    'rarity' => $this->rarity($c),
                    'type' => $this->cardType($c),
                ], array_slice($cards, 0, 8)),
            ];
        }

        $vertical = Vertical::updateOrCreate(['slug' => 'tcg'], ['name' => 'Trading Card Games']);
        $line = ProductLine::updateOrCreate(
            ['vertical_id' => $vertical->id, 'slug' => 'riftbound'],
            ['name' => 'Riftbound'],
        );

        $setCache = [];
        $imageCount = 0;

        foreach ($cards as $card) {
            $code = $this->setCode($card);
            $set = $setCache[$code] ??= $this->upsertSet($line->id, $code, $this->setName($card));

            $imagePath = null;
            if ($withImages) {
                $imagePath = $this->images->store($set->slug, (string) $card['id'], $card['cardImage']['url'] ?? null, 'riftbound');
                if ($imagePath) {
                    $imageCount++;
                }
            }

            ($this->create)(
                vertical: $vertical,
                productLine: $line,
                set: $set,
                itemType: ItemType::Single,
                name: (string) ($card['name'] ?? 'Unknown'),
                number: $this->number($card),
                attributes: array_filter([
                    'language' => 'en',
                    'variant' => 'normal',
                    'rarity' => $this->rarity($card),
                    'type' => $this->cardType($card),
                    'faction' => $this->domain($card),
                    'illustrator' => $this->illustrator($card),
                    'cost' => $card['energy']['value']['id'] ?? null,
                    'body_text' => $this->text($card),
                ], fn ($v) => $v !== null && $v !== ''),
                externalIds: array_filter([
                    'riftbound_id' => $card['id'] ?? null,
                    'riftbound_public_code' => $card['publicCode'] ?? null,
                ]),
                primaryImagePath: $imagePath,
            );
        }

        return ['cards' => count($cards), 'images' => $imageCount, 'sets' => $bySet, 'sample' => []];
    }

    /**
     * "UNL-001/219" => "001/219", "UNL-T01" => "T01". See the class note on why
     * collectorNumber cannot be used.
     *
     * @param  array<string, mixed>  $card
     */
    protected function number(array $card): ?string
    {
        $code = $card['publicCode'] ?? null;

        if (! $code) {
            // No public code: fall back to the gallery id's own trailing part,
            // which is still unique, rather than to a colliding collectorNumber.
            return isset($card['id']) ? (string) $card['id'] : null;
        }

        return (string) preg_replace('/^[A-Za-z]+-/', '', (string) $code);
    }

    /** @param  array<string, mixed>  $card */
    protected function setCode(array $card): string
    {
        return (string) ($card['set']['value']['id'] ?? 'riftbound');
    }

    /** @param  array<string, mixed>  $card */
    protected function setName(array $card): string
    {
        return (string) ($card['set']['value']['label'] ?? $this->setCode($card));
    }

    /** @param  array<string, mixed>  $card */
    protected function rarity(array $card): ?string
    {
        return $card['rarity']['value']['label'] ?? null;
    }

    /** @param  array<string, mixed>  $card */
    protected function cardType(array $card): ?string
    {
        return $card['cardType']['type'][0]['label'] ?? null;
    }

    /** Domain is multi-valued (a card can be dual-domain); join for the facet. */
    protected function domain(array $card): ?string
    {
        $labels = array_filter(array_map(
            fn ($d) => $d['label'] ?? null,
            $card['domain']['values'] ?? [],
        ));

        return $labels === [] ? null : implode(', ', $labels);
    }

    /** @param  array<string, mixed>  $card */
    protected function illustrator(array $card): ?string
    {
        $labels = array_filter(array_map(
            fn ($a) => $a['label'] ?? null,
            $card['illustrator']['values'] ?? [],
        ));

        return $labels === [] ? null : implode(', ', $labels);
    }

    /**
     * Rules text. The feed carries it as `text`, occasionally structured, so
     * flatten anything that isn't already a string.
     *
     * @param  array<string, mixed>  $card
     */
    protected function text(array $card): ?string
    {
        $text = $card['text'] ?? null;

        if (is_string($text)) {
            return trim($text) ?: null;
        }

        if (is_array($text)) {
            $flat = trim(implode(' ', array_filter($text, 'is_string')));

            return $flat ?: null;
        }

        return null;
    }

    protected function upsertSet(int $productLineId, string $code, string $name): Set
    {
        $set = Set::query()
            ->where('product_line_id', $productLineId)
            ->where('external_ids->riftbound_set_code', $code)
            ->first() ?? new Set;

        $set->forceFill([
            'product_line_id' => $productLineId,
            'slug' => Str::slug($name) ?: Str::lower($code),
            'name' => $name,
            'code' => $code,
            'language' => 'en',
            'set_family' => $name,
            'external_ids' => ['riftbound_set_code' => $code],
        ])->save();

        return $set;
    }
}
