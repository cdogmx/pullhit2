<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Affiliate\EbayAffiliate;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbayBrowseClient;
use App\Support\Ebay\SealedSearch;
use Illuminate\Support\Facades\Cache;

/**
 * Active "buy it now" listings + affiliate shop links for a card. Live eBay
 * listings come from the Browse API (cached, free); the "Shop on eBay" links are
 * always present (affiliate-tagged when configured), one per condition/grade so
 * the UI can default to Near Mint and offer the rest in a dropdown.
 *
 * Sealed products are a different market and take a different route through
 * here: retail-wording search, "Sealed" refinements instead of the singles
 * condition/grade ladder, and a product-identity filter on what comes back.
 */
class GetCardListings
{
    /** Condition + graded search refinements appended to the card query. */
    public const OPTIONS = [
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

    /**
     * Refinements for a sealed product. Grades and card conditions are
     * meaningless here — appending "Near Mint" to a booster box search is what
     * made the panel return near-mint SINGLES from the same set.
     */
    public const SEALED_OPTIONS = [
        ['label' => 'Sealed', 'group' => 'Condition', 'suffix' => 'Sealed'],
        ['label' => 'Factory Sealed', 'group' => 'Condition', 'suffix' => 'Factory Sealed'],
        ['label' => 'Any', 'group' => 'Condition', 'suffix' => ''],
    ];

    public function __construct(
        protected EbayBrowseClient $browse,
        protected EbayAffiliate $ebay,
    ) {}

    /**
     * @return array{
     *   listings: array<int, mixed>,
     *   ebay_options: array<int, array{label: string, group: string, url: string, suffix: string}>,
     *   selected: string,
     *   configured: bool
     * }
     */
    public function __invoke(CatalogItem $item, ?string $optionLabel = null): array
    {
        $sealed = $item->item_type === ItemType::Sealed;
        $query = $sealed ? SealedSearch::query($item) : $this->singleQuery($item);

        $optionSet = $sealed ? self::SEALED_OPTIONS : self::OPTIONS;
        $selected = $this->resolveOption($optionLabel, $optionSet);
        $suffix = $selected['suffix'];

        // Browse API buy-it-now asks for the chosen condition/grade (affiliate-tagged).
        // Short-cache empty results so missing keys / blips recover quickly.
        $listings = [];
        if ($this->browse->configured()) {
            $cacheKey = 'ebay:listings:'.$item->id.':v5:'.md5($suffix);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $listings = $cached;
            } else {
                $found = $this->relevant($item, $this->browse->search(trim($query.' '.$suffix), 12));
                // Bare query only when the selected refinement returns nothing.
                $listings = $found !== [] ? $found : $this->relevant($item, $this->browse->search($query, 12));
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
            'suffix' => $o['suffix'],
            'url' => $this->ebay->searchUrl(trim($query.' '.$o['suffix'])),
        ], $optionSet);

        return [
            'listings' => $listings,
            'ebay_options' => $options,
            'selected' => $selected['label'],
            'configured' => $this->browse->configured(),
        ];
    }

    /**
     * The keyword string for a single: name + number + set, pinned to this
     * printing's qualifiers and language — a Japanese card's asks come from
     * Japanese listings, not English ones.
     */
    protected function singleQuery(CatalogItem $item): string
    {
        return trim(implode(' ', array_filter(array_merge([
            $item->name,
            $item->number,
            $item->set?->name,
        ], CardSearchTerms::qualifiers($item), [CardSearchTerms::languageKeyword($item)]))));
    }

    /**
     * Drop listings that aren't this product. Keyword relevance alone is not an
     * answer: it leaks other languages' printings for a single, and for a sealed
     * product it happily returns a different set's box, a lot, or an empty one.
     *
     * @param  array<int, array<string, mixed>>  $listings
     * @return array<int, array<string, mixed>>
     */
    protected function relevant(CatalogItem $item, array $listings): array
    {
        $sealed = $item->item_type === ItemType::Sealed;

        return array_values(array_filter($listings, function (array $l) use ($item, $sealed) {
            $title = (string) ($l['title'] ?? '');

            if (! CardSearchTerms::matchesLanguage($item, $title)) {
                return false;
            }

            // requireSet: this page is about ONE product, so a sibling set's box
            // is a plain wrong answer here even though it's a fine sold comp.
            return ! $sealed || SealedSearch::matches($item, $title, requireSet: true);
        }));
    }

    /**
     * @param  array<int, array{label: string, group: string, suffix: string}>  $options
     * @return array{label: string, group: string, suffix: string}
     */
    protected function resolveOption(?string $label, array $options): array
    {
        if ($label !== null && $label !== '') {
            foreach ($options as $o) {
                if (strcasecmp($o['label'], $label) === 0) {
                    return $o;
                }
            }
        }

        return $options[0]; // Near Mint for singles, Sealed for sealed products
    }
}
