<?php

namespace App\Actions\Catalog;

use App\Models\CatalogItem;
use App\Support\Affiliate\EbayAffiliate;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbayBrowseClient;
use Illuminate\Support\Facades\Cache;

/**
 * Active "buy it now" listings + affiliate shop links for a card. Live eBay
 * listings come from the Browse API (cached, free); the "Shop on eBay" links are
 * always present (affiliate-tagged when configured), one per condition/grade so
 * the UI can default to Near Mint and offer the rest in a dropdown.
 */
class GetCardListings
{
    /** Condition + graded search refinements appended to the card query. */
    private const EBAY_OPTIONS = [
        ['label' => 'Near Mint', 'group' => 'Condition', 'suffix' => 'Near Mint'],
        ['label' => 'Lightly Played', 'group' => 'Condition', 'suffix' => 'Lightly Played'],
        ['label' => 'Moderately Played', 'group' => 'Condition', 'suffix' => 'Moderately Played'],
        ['label' => 'Heavily Played', 'group' => 'Condition', 'suffix' => 'Heavily Played'],
        ['label' => 'Damaged', 'group' => 'Condition', 'suffix' => 'Damaged'],
        ['label' => 'PSA 10', 'group' => 'Graded', 'suffix' => 'PSA 10'],
        ['label' => 'PSA 9', 'group' => 'Graded', 'suffix' => 'PSA 9'],
        ['label' => 'PSA 8', 'group' => 'Graded', 'suffix' => 'PSA 8'],
        ['label' => 'BGS 10', 'group' => 'Graded', 'suffix' => 'BGS 10'],
        ['label' => 'BGS 9.5', 'group' => 'Graded', 'suffix' => 'BGS 9.5'],
        ['label' => 'CGC 10', 'group' => 'Graded', 'suffix' => 'CGC 10'],
        ['label' => 'CGC 9.5', 'group' => 'Graded', 'suffix' => 'CGC 9.5'],
    ];

    public function __construct(
        protected EbayBrowseClient $browse,
        protected EbayAffiliate $ebay,
    ) {}

    /**
     * @return array{listings: array<int, mixed>, ebay_options: array<int, array{label: string, group: string, url: string}>, configured: bool}
     */
    public function __invoke(CatalogItem $item): array
    {
        $query = trim(implode(' ', array_filter(array_merge([
            $item->name,
            $item->number,
            $item->set?->name,
        ], CardSearchTerms::qualifiers($item)))));

        // Default tile list: Near Mint buy-it-now asks (Browse API, affiliate-tagged).
        // Short-cache empty results so missing keys / blips recover quickly.
        $listings = [];
        if ($this->browse->configured()) {
            $cacheKey = "ebay:listings:{$item->id}:v2";
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $listings = $cached;
            } else {
                $found = $this->browse->search(trim($query.' Near Mint'), 8);
                $listings = $found !== [] ? $found : $this->browse->search($query, 8);
                Cache::put(
                    $cacheKey,
                    $listings,
                    $listings === [] ? now()->addMinutes(15) : now()->addHours(6),
                );
            }
        }

        $options = array_map(fn (array $o) => [
            'label' => $o['label'],
            'group' => $o['group'],
            'url' => $this->ebay->searchUrl(trim($query.' '.$o['suffix'])),
        ], self::EBAY_OPTIONS);

        return [
            'listings' => $listings,
            'ebay_options' => $options,
            'configured' => $this->browse->configured(),
        ];
    }
}
