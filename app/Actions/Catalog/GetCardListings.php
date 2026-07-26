<?php

namespace App\Actions\Catalog;

use App\Enums\ItemType;
use App\Models\CatalogItem;
use App\Support\Affiliate\EbayAffiliate;
use App\Support\Ebay\CardSearchTerms;
use App\Support\Ebay\EbayBrowseClient;
use App\Support\Ebay\SealedSearch;
use App\Support\Ebay\SoldCompClassifier;
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

    /**
     * How many listings to pull from eBay before filtering, and how many survive
     * to the page. The gap matters: we ask for relevance-ordered results and do
     * our own identity filtering, so the window has to be wide enough that real
     * listings are in it. Ordering by price instead would hand us the cheapest N
     * of hundreds of matches — for a $200 booster box that is, by construction,
     * entirely other people's singles, loose packs, and wrong-language boxes.
     */
    private const FETCH_LIMIT = 50;

    private const DISPLAY_LIMIT = 12;

    /**
     * Grading marks in a title. A raw refinement drops anything carrying one —
     * a PSA 10 slab is not an asking price for a Near Mint raw card, and the
     * keyword query alone happily returns both. Mirrors the identical rule the
     * for-sale ask ingest applies (IngestForSaleListings::STATES).
     */
    private const GRADED_MARK = '/\b(psa|bgs|cgc|sgc|graded|gem\s*mint)\b/i';

    public function __construct(
        protected EbayBrowseClient $browse,
        protected EbayAffiliate $ebay,
        protected SoldCompClassifier $classifier,
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
            $cacheKey = 'ebay:listings:'.$item->id.':v6:'.md5($suffix);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                $listings = $cached;
            } else {
                $found = $this->fetch($item, trim($query.' '.$suffix), $selected);
                // Bare query only when the selected refinement returns nothing.
                $listings = $found !== []
                    ? $found
                    : $this->fetch($item, $query, $selected);
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
     * One Browse pull: a wide relevance-ordered window, narrowed to listings
     * that really are this product, then presented cheapest-first — which is
     * what a buyer wants to see, and is a display concern rather than something
     * to ask eBay for.
     *
     * @param  array{label: string, group: string, suffix: string}  $option
     * @return array<int, array<string, mixed>>
     */
    protected function fetch(CatalogItem $item, string $query, array $option): array
    {
        $raw = $this->browse->search($query, self::FETCH_LIMIT, sort: null);

        // Prefer listings that state the collector number — that's what tells
        // one Charmander from every other Charmander. But plenty of sellers
        // simply don't write it (One Piece codes like OP06-093 especially), so
        // it's a preference, not a gate: fall back rather than show an empty
        // panel for a card that demonstrably has listings.
        $listings = $this->relevant($item, $raw, $option, requireNumber: true);

        if ($listings === []) {
            $listings = $this->relevant($item, $raw, $option, requireNumber: false);
        }

        usort($listings, fn (array $a, array $b) => ($a['price_cents'] ?? 0) <=> ($b['price_cents'] ?? 0));

        return array_slice($listings, 0, self::DISPLAY_LIMIT);
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
     * @param  array{label: string, group: string, suffix: string}  $option
     * @return array<int, array<string, mixed>>
     */
    protected function relevant(
        CatalogItem $item,
        array $listings,
        array $option,
        bool $requireNumber = true,
    ): array {
        $sealed = $item->item_type === ItemType::Sealed;

        return array_values(array_filter($listings, function (array $l) use ($item, $sealed, $option, $requireNumber) {
            $title = (string) ($l['title'] ?? '');

            if (! CardSearchTerms::matchesLanguage($item, $title)) {
                return false;
            }

            // requireSet: this page is about ONE product, so a sibling set's box
            // is a plain wrong answer here even though it's a fine sold comp.
            if ($sealed) {
                return SealedSearch::matches($item, $title, requireSet: true);
            }

            // Singles get the same gates their sold comps get — blocklist,
            // lots, multi-card bundles, name match, printing. An asking price
            // for "YOU PICK any card" isn't an asking price for THIS card.
            if ($this->classifier->titleRejectReason($item, $title) !== null) {
                return false;
            }

            // The collector number, when the card has one. Same reasoning as
            // requireSet above: a different Charmander is a perfectly good comp
            // for nothing, but on THIS card's page it's simply the wrong card,
            // and "Charmander" alone matches every Charmander ever printed.
            if ($requireNumber && ! $this->matchesNumber($item, $title)) {
                return false;
            }

            return $this->matchesGrade($option, $title);
        }));
    }

    /**
     * Does the title carry this card's collector number? Written any of the ways
     * sellers write it — "38", "#38", "038", "38/159" — so leading zeros and a
     * set-size denominator both match. Cards with no number (most promos and
     * sealed) impose no test.
     */
    protected function matchesNumber(CatalogItem $item, string $title): bool
    {
        $number = trim((string) $item->number);

        if ($number === '') {
            return true;
        }

        return (bool) preg_match(
            '/\b0*'.preg_quote($number, '/').'\b/i',
            $title,
        );
    }

    /**
     * Does the title agree with the chosen refinement? A graded option keeps
     * only titles stating that exact slab ("PSA 10"); a raw condition keeps only
     * titles with no grading mark at all. The keyword search is fuzzy about
     * both, so without this the Near Mint tab lists PSA 10s at 5× the price.
     *
     * @param  array{label: string, group: string, suffix: string}  $option
     */
    protected function matchesGrade(array $option, string $title): bool
    {
        if ($option['group'] !== 'Graded') {
            return ! preg_match(self::GRADED_MARK, $title);
        }

        // "PSA 10" / "BGS 9.5" → the grader and the grade, adjacent.
        [$company, $grade] = array_pad(explode(' ', $option['suffix'], 2), 2, '');

        return (bool) preg_match(
            '/\b'.preg_quote($company, '/').'\s*'.preg_quote($grade, '/').'\b/i',
            $title,
        );
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
