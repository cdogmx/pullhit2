<?php

namespace App\Actions\Marketplace;

use App\Enums\Condition;
use App\Enums\ItemType;
use App\Enums\ListingCategory;
use App\Models\CatalogItem;
use App\Models\CollectionItem;
use App\Models\MarketValue;
use App\Support\Marketplace\CardHit;

/**
 * Start a listing from the thing the seller is already looking at.
 *
 * "List for sale" on a card page or a collection row skips the part of the form
 * that has already been answered elsewhere: which card this is, what condition
 * it is in, whose slab it is in and what it graded. A seller who has kept a
 * collection has said all of that once already.
 *
 * What it never fills in is the price. That is the one decision that has to be
 * the seller's, and a suggested number is not a suggestion — it is an anchor.
 * The market value rides along on the linked card instead, where it informs the
 * price without setting it.
 */
class PrefillListing
{
    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(?CatalogItem $card = null, ?CollectionItem $holding = null): ?array
    {
        $card = $holding?->catalogItem ?? $card;

        if (! $card) {
            return null;
        }

        $card->loadMissing(['set', 'productLine']);

        $category = $this->category($card, $holding);

        return [
            'title' => $this->title($card),
            'category' => $category->value,
            // A slab's grade replaces its condition, so offering both would be
            // offering a contradiction.
            'condition' => $category->hasCondition()
                ? ($holding?->condition?->value ?? Condition::NearMint->value)
                : null,
            'grading_company_id' => $category->isGraded() ? $holding?->grading_company_id : null,
            'grade' => $category->isGraded() ? $this->grade($holding?->grade) : null,
            'card' => CardHit::for($card, $this->value($card, $holding)),
        ];
    }

    /**
     * A graded copy is a slab whatever the catalog calls the card; everything
     * else is what the catalog says it is.
     */
    private function category(CatalogItem $card, ?CollectionItem $holding): ListingCategory
    {
        if ($holding?->grading_company_id !== null) {
            return ListingCategory::GradedSlab;
        }

        return $card->item_type === ItemType::Sealed
            ? ListingCategory::Sealed
            : ListingCategory::RawSingle;
    }

    /** "Charizard ex #223 — Obsidian Flames": what a buyer searches for. */
    private function title(CatalogItem $card): string
    {
        $name = trim(($card->display_name ?? $card->name).($card->number ? ' #'.$card->number : ''));
        $title = $card->set?->name ? $name.' — '.$card->set->name : $name;

        return mb_substr($title, 0, 255);
    }

    /** 10.0 reads as "10", 9.5 stays "9.5". */
    private function grade(?float $grade): ?string
    {
        return $grade === null ? null : rtrim(rtrim(sprintf('%.1f', $grade), '0'), '.');
    }

    /**
     * What this exact copy is worth — the PSA 10 price for a PSA 10, not the raw
     * one. Falls back to ungraded when we have no figure for that grade, which
     * is still a better floor than showing nothing.
     */
    private function value(CatalogItem $card, ?CollectionItem $holding): ?int
    {
        $state = $holding?->stateKey();

        if ($state) {
            $graded = MarketValue::query()
                ->where('catalog_item_id', $card->id)
                ->where('state_key', $state)
                ->value('median');

            if ($graded !== null) {
                return (int) $graded;
            }
        }

        $raw = MarketValue::query()
            ->where('catalog_item_id', $card->id)
            ->whereNull('grading_company_id')
            ->whereIn('state_key', ['NM', 'SEALED'])
            ->value('median');

        return $raw === null ? null : (int) $raw;
    }
}
