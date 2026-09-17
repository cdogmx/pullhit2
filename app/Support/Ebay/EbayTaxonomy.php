<?php

namespace App\Support\Ebay;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * eBay's own vocabulary for a category's item aspects.
 *
 * The "Set" aspect is a closed list of 2,290 values, and it is the thing worth
 * filtering a card search on — pinning it takes the 30th Celebration's Sylveon
 * from 126 listings to 22, the rest being a Sylveon from a 2021 set that shares
 * nearly every word.
 *
 * Fetched rather than written down. The list changes with every release, and a
 * copy pasted into the repo is wrong by the next set.
 */
class EbayTaxonomy
{
    /** The vocabulary changes a few times a year; a day is plenty fresh. */
    private const CACHE_HOURS = 24;

    public function __construct(
        protected EbayBrowseClient $browse,
    ) {}

    /**
     * Every value eBay accepts for an aspect in a category.
     *
     * @return array<int, string>
     */
    public function aspectValues(string $aspect, string $categoryId): array
    {
        $key = "ebay:taxonomy:{$categoryId}:".md5($aspect);

        return Cache::remember($key, now()->addHours(self::CACHE_HOURS), function () use ($aspect, $categoryId) {
            $token = $this->browse->accessToken();

            if ($token === null) {
                return [];
            }

            $c = config('services.ebay');

            // The US category tree is id "0" — a string that is falsy in PHP,
            // so it is compared to null rather than tested for truth.
            $tree = Http::withToken($token)->timeout(30)
                ->get($c['base_url'].'/commerce/taxonomy/v1/get_default_category_tree_id', [
                    'marketplace_id' => $c['marketplace_id'] ?? 'EBAY_US',
                ]);

            $treeId = $tree->json('categoryTreeId');

            if ($treeId === null) {
                Log::warning('ebay.taxonomy.no_tree', ['status' => $tree->status()]);

                return [];
            }

            $response = Http::withToken($token)->timeout(60)
                ->get($c['base_url']."/commerce/taxonomy/v1/category_tree/{$treeId}/get_item_aspects_for_category", [
                    'category_id' => $categoryId,
                ]);

            if (! $response->successful()) {
                Log::warning('ebay.taxonomy.failed', ['status' => $response->status(), 'aspect' => $aspect]);

                return [];
            }

            foreach ($response->json('aspects') ?? [] as $a) {
                if (($a['localizedAspectName'] ?? null) !== $aspect) {
                    continue;
                }

                return array_values(array_filter(array_column(
                    $a['aspectValues'] ?? [], 'localizedValue',
                )));
            }

            return [];
        });
    }
}
