<?php

namespace App\Actions\Catalog;

use App\Models\Set;
use App\Support\Catalog\PokemonTcgClient;

/**
 * Diff a set's cards on pokemontcg.io against what we've imported (by ptcgio_id)
 * to surface gaps — cards the source has that our catalog is missing.
 */
class MissingCardsReport
{
    public function __construct(
        protected PokemonTcgClient $client,
    ) {}

    /**
     * @return array{expected: int, present: int, missing: array<int, array{id: string, number: ?string, name: ?string}>}
     */
    public function __invoke(Set $set): array
    {
        $ptcgioId = $set->external_ids['ptcgio_id'] ?? null;
        if (! $ptcgioId) {
            return ['expected' => 0, 'present' => 0, 'missing' => []];
        }

        $cards = $this->client->cards($ptcgioId);

        $have = $set->catalogItems()->get(['external_ids'])
            ->map(fn ($i) => $i->external_ids['ptcgio_id'] ?? null)
            ->filter()
            ->unique();

        $missing = collect($cards)
            ->reject(fn ($c) => $have->contains($c['id'] ?? null))
            ->map(fn ($c) => [
                'id' => (string) ($c['id'] ?? ''),
                'number' => $c['number'] ?? null,
                'name' => $c['name'] ?? null,
            ])
            ->values()
            ->all();

        return ['expected' => count($cards), 'present' => $have->count(), 'missing' => $missing];
    }
}
