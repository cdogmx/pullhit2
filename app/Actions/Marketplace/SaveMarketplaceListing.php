<?php

namespace App\Actions\Marketplace;

use App\Enums\ListingCategory;
use App\Enums\ListingStatus;
use App\Models\CatalogItem;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Create or update one listing, and keep the facets honest.
 *
 * A category decides which facets mean anything: a slab has a grader, a grade
 * and a cert and no raw condition; a raw single has a condition and none of the
 * grading fields. Left to the form alone, a seller who fills in a grade and then
 * switches to "raw single" leaves a PSA 10 attached to an ungraded card — which
 * then matches graded comps and prices the listing at several times its worth.
 * So the facets that do not apply are cleared here rather than merely hidden.
 */
class SaveMarketplaceListing
{
    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public function __invoke(User $seller, array $data, ?MarketplaceListing $listing = null): MarketplaceListing
    {
        $listing ??= new MarketplaceListing(['user_id' => $seller->id]);

        $category = $data['category'] instanceof ListingCategory
            ? $data['category']
            : ListingCategory::from((string) $data['category']);

        $listing->fill([
            'user_id' => $listing->user_id ?? $seller->id,
            'category' => $category,
            'title' => trim((string) $data['title']),
            'description' => $this->nullIfBlank($data['description'] ?? null),
            'price_cents' => (int) $data['price_cents'],
            'currency' => $data['currency'] ?? 'USD',
            'accepts_offers' => (bool) ($data['accepts_offers'] ?? true),
            'accepts_direct' => (bool) ($data['accepts_direct'] ?? true),
            'accepts_escrow' => (bool) ($data['accepts_escrow'] ?? true),

            // Grading facets, kept only where the category has them.
            'grading_company_id' => $category->isGraded() ? ($data['grading_company_id'] ?? null) : null,
            'grade' => $category->isGraded() ? $this->nullIfBlank($data['grade'] ?? null) : null,
            'cert_number' => $category->isGraded() ? $this->nullIfBlank($data['cert_number'] ?? null) : null,

            // A slab's grade IS its condition; carrying both invites them to disagree.
            'condition' => $category->hasCondition() ? $this->nullIfBlank($data['condition'] ?? null) : null,
        ]);

        $this->attachCard($listing, $data);

        // A listing with no way to be bought is a listing nobody can act on.
        if (! $listing->accepts_direct && ! $listing->accepts_escrow) {
            $listing->accepts_direct = true;
        }

        $listing->status = $this->statusFor($listing, $data);

        if ($listing->status === ListingStatus::Active && $listing->expires_at === null) {
            $listing->expires_at = Carbon::now()->addDays(
                (int) config('marketplace.listing_days', 30),
            );
        }

        $listing->save();

        return $listing->refresh();
    }

    /**
     * Link the catalogued card, and copy its set code and number across so an
     * unlinked listing is still searchable by them and a linked one still reads
     * correctly if the catalog row later moves.
     *
     * @param  array<string, mixed>  $data
     */
    private function attachCard(MarketplaceListing $listing, array $data): void
    {
        $id = $data['catalog_item_id'] ?? null;

        if (! $id) {
            $listing->catalog_item_id = null;
            $listing->set_code = $this->nullIfBlank($data['set_code'] ?? null);
            $listing->card_number = $this->nullIfBlank($data['card_number'] ?? null);

            return;
        }

        $card = CatalogItem::with('set')->find($id);

        $listing->catalog_item_id = $card?->id;
        $listing->set_code = $card?->set?->code ?? $this->nullIfBlank($data['set_code'] ?? null);
        $listing->card_number = $card?->number ?? $this->nullIfBlank($data['card_number'] ?? null);
    }

    /**
     * Drafts stay drafts until published; a live listing being edited stays
     * live. Nothing here can move a listing to sold or pending — those belong
     * to a deal, not to the seller editing a title.
     *
     * @param  array<string, mixed>  $data
     */
    private function statusFor(MarketplaceListing $listing, array $data): ListingStatus
    {
        $wanted = ($data['publish'] ?? false) ? ListingStatus::Active : ListingStatus::Draft;

        if (! $listing->exists) {
            return $wanted;
        }

        return match ($listing->status) {
            ListingStatus::Draft => $wanted,
            // Re-publishing an expired listing is the renewal path.
            ListingStatus::Expired => ($data['publish'] ?? false) ? ListingStatus::Active : ListingStatus::Expired,
            default => $listing->status,
        };
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
