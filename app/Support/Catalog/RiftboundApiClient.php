<?php

namespace App\Support\Catalog;

use Illuminate\Support\Facades\Http;

/**
 * Reads the Riftbound card gallery from Riot's publishing-content service — the
 * same paginated feed playriftbound.com/card-gallery renders from.
 *
 * The gallery page also inlines the whole list in its Next.js __NEXT_DATA__ blob,
 * which is one request instead of six, but it means parsing HTML and depending on
 * a framework's internals. This feed returns identical cards (verified: both give
 * the same 1,189) and is the stabler contract.
 *
 * Note the feed's `totalItems` over-reports — it counts before the service drops
 * unpublished entries, so pages come back short (198, 195) and the real total is
 * lower. Paginate until a page comes back empty rather than trusting the count.
 */
class RiftboundApiClient
{
    private const BASE = 'https://content.publishing.riotgames.com/publishing-content/v2.0/public/channel/riftbound_website/list/riftbound_gallery_cards';

    private const PAGE = 200;

    /** Hard stop so a feed change can never spin this forever. */
    private const MAX_PAGES = 40;

    /**
     * Every card in the gallery, de-duplicated on the card's stable id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cards(string $locale = 'en_US'): array
    {
        $cards = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $batch = $this->page($page * self::PAGE, $locale);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $card) {
                if ($id = $card['id'] ?? null) {
                    $cards[$id] = $card;
                }
            }
        }

        return array_values($cards);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function page(int $from, string $locale): array
    {
        $response = Http::timeout(60)
            ->retry(2, 1000, throw: false)
            ->get(self::BASE, ['locale' => $locale, 'from' => $from, 'limit' => self::PAGE]);

        if (! $response->successful()) {
            return [];
        }

        return $response->json('data') ?? [];
    }
}
